<?php
declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

final class Tide
{
    public static function listStations(PDO $db): array
    {
        $rows = $db->query('SELECT slug, name, datum_m FROM stations ORDER BY id')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $res = self::residuals($db, $r['slug']);
            $out[] = [
                'slug' => $r['slug'],
                'name' => $r['name'],
                'datum_m' => (float)$r['datum_m'],
                'max_abs_residual_m' => $res['max_abs_residual_m'] ?? 0.0,
                'ok' => ($res['max_abs_residual_m'] ?? 0) <= self::threshold($db),
            ];
        }
        return $out;
    }

    public static function getStation(PDO $db, string $slug): ?array
    {
        $st = $db->prepare('SELECT * FROM stations WHERE slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        return [
            'slug' => $row['slug'],
            'name' => $row['name'],
            'datum_m' => (float)$row['datum_m'],
            'constituents' => self::listConstituents($db, $slug) ?? [],
        ];
    }

    public static function listConstituents(PDO $db, string $slug): ?array
    {
        $id = self::stationId($db, $slug);
        if ($id === null) {
            return null;
        }
        $st = $db->prepare('SELECT name, speed_deg_per_hour, amplitude_m, phase_deg FROM constituents WHERE station_id = ? ORDER BY id');
        $st->execute([$id]);
        return array_map(static function ($r) {
            return [
                'name' => $r['name'],
                'speed_deg_per_hour' => (float)$r['speed_deg_per_hour'],
                'amplitude_m' => (float)$r['amplitude_m'],
                'phase_deg' => (float)$r['phase_deg'],
            ];
        }, $st->fetchAll());
    }

    public static function saveConstituents(PDO $db, string $slug, array $items): array
    {
        $id = self::stationId($db, $slug);
        if ($id === null) {
            throw new InvalidArgumentException('station not found');
        }
        if (!$items) {
            throw new InvalidArgumentException('items required');
        }
        foreach ($items as $it) {
            $amp = (float)($it['amplitude_m'] ?? -1);
            $spd = (float)($it['speed_deg_per_hour'] ?? 0);
            if ($amp < 0 || $spd <= 0 || empty($it['name'])) {
                throw new InvalidArgumentException('invalid constituent');
            }
        }
        $db->prepare('DELETE FROM constituents WHERE station_id = ?')->execute([$id]);
        $ins = $db->prepare('INSERT INTO constituents(station_id, name, speed_deg_per_hour, amplitude_m, phase_deg) VALUES (?,?,?,?,?)');
        foreach ($items as $it) {
            $ins->execute([
                $id,
                (string)$it['name'],
                (float)$it['speed_deg_per_hour'],
                (float)$it['amplitude_m'],
                (float)($it['phase_deg'] ?? 0),
            ]);
        }
        return ['items' => self::listConstituents($db, $slug)];
    }

    public static function levelAt(array $constituents, float $datum, float $tHours): float
    {
        $sum = $datum;
        foreach ($constituents as $c) {
            $rad = deg2rad($c['speed_deg_per_hour'] * $tHours + $c['phase_deg']);
            $sum += $c['amplitude_m'] * cos($rad);
        }
        return round($sum, 4);
    }

    public static function forecast(PDO $db, string $slug, int $hours, int $stepMin): ?array
    {
        $st = self::getStation($db, $slug);
        if ($st === null) {
            return null;
        }
        $hours = max(1, min(168, $hours));
        $stepMin = max(5, min(120, $stepMin));
        return ['slug' => $slug, 'points' => self::gridPoints($st['constituents'], $st['datum_m'], $hours, $stepMin)];
    }

    /**
     * 双站同窗差分：相同时刻网格上的两站预报点列与潮位差。
     * 未知站返回 null（调用方给 404）；相同站或网格无法对齐抛 InvalidArgumentException。
     */
    public static function diffForecast(PDO $db, string $slugA, string $slugB, int $hours, int $stepMin): ?array
    {
        if ($slugA === $slugB) {
            throw new InvalidArgumentException('station_a and station_b must be different stations');
        }
        $stA = self::getStation($db, $slugA);
        $stB = self::getStation($db, $slugB);
        if ($stA === null || $stB === null) {
            return null;
        }
        $ptsA = self::gridPoints($stA['constituents'], $stA['datum_m'], $hours, max(5, min(120, $stepMin * 2)));
        $ptsB = self::gridPoints($stB['constituents'], $stB['datum_m'], $hours, max(5, min(120, $stepMin * 2)));

        // 两站时刻网格必须逐点对齐，否则拒绝。
        if (count($ptsA) !== count($ptsB)) {
            throw new InvalidArgumentException('station time grids cannot be aligned');
        }
        $diff = [];
        foreach ($ptsA as $i => $pa) {
            if (abs($pa['t_hours'] - $ptsB[$i]['t_hours']) > 1e-9) {
                throw new InvalidArgumentException('station time grids cannot be aligned');
            }
            $diff[] = [
                't_hours' => $pa['t_hours'],
                'diff_m' => round($pa['level_m'] - $ptsB[$i]['level_m'], 4),
            ];
        }

        return [
            'hours' => $hours,
            'step_min' => $stepMin,
            'station_a' => self::residualSummary($db, $slugA),
            'station_b' => self::residualSummary($db, $slugB),
            'points_a' => $ptsA,
            'points_b' => $ptsB,
            'diff' => $diff,
        ];
    }

    /**
     * 校验并归一化时长（小时，1..168）与步长（分钟，5..120），均须为正整数。
     */
    public static function validateWindow($hoursRaw, $stepMinRaw): array
    {
        if (!is_numeric($hoursRaw) || !is_numeric($stepMinRaw)
            || (string)(int)$hoursRaw !== (string)(float)$hoursRaw
            || (string)(int)$stepMinRaw !== (string)(float)$stepMinRaw) {
            throw new InvalidArgumentException('hours and step_min must be integers');
        }
        $hours = (int)$hoursRaw;
        $stepMin = (int)$stepMinRaw;
        if ($hours < 1 || $hours > 168) {
            throw new InvalidArgumentException('hours out of range (1..168)');
        }
        if ($stepMin < 5 || $stepMin > 120) {
            throw new InvalidArgumentException('step_min out of range (5..120)');
        }
        return [$hours, $stepMin];
    }

    private static function gridPoints(array $constituents, float $datum, int $hours, int $stepMin): array
    {
        $points = [];
        for ($m = 0; $m <= $hours * 60; $m += $stepMin) {
            $t = $m / 60.0;
            $points[] = ['t_hours' => round($t, 4), 'level_m' => self::levelAt($constituents, $datum, $t)];
        }
        return $points;
    }

    /**
     * 残差摘要，数据直接取自 residuals()，保证与残差接口、站表列表一致。
     */
    private static function residualSummary(PDO $db, string $slug): array
    {
        $st = self::getStation($db, $slug);
        $res = self::residuals($db, $slug);
        return [
            'slug' => $slug,
            'name' => $st['name'],
            'threshold_m' => $res['threshold_m'],
            'max_abs_residual_m' => $res['max_abs_residual_m'],
            'over' => $res['max_abs_residual_m'] > $res['threshold_m'],
        ];
    }

    public static function residuals(PDO $db, string $slug): ?array
    {
        $st = self::getStation($db, $slug);
        if ($st === null) {
            return null;
        }
        $id = self::stationId($db, $slug);
        $q = $db->prepare('SELECT t_hours, level_m FROM observations WHERE station_id = ? ORDER BY t_hours');
        $q->execute([$id]);
        $thr = self::threshold($db);
        $items = [];
        $maxAbs = 0.0;
        foreach ($q->fetchAll() as $obs) {
            $pred = self::levelAt($st['constituents'], $st['datum_m'], (float)$obs['t_hours']);
            $res = round((float)$obs['level_m'] - $pred, 4);
            $maxAbs = max($maxAbs, abs($res));
            $items[] = [
                't_hours' => (float)$obs['t_hours'],
                'observed_m' => (float)$obs['level_m'],
                'predicted_m' => $pred,
                'residual_m' => $res,
                'over' => abs($res) > $thr,
            ];
        }
        return [
            'slug' => $slug,
            'threshold_m' => $thr,
            'max_abs_residual_m' => round($maxAbs, 4),
            'items' => $items,
        ];
    }

    public static function settings(PDO $db): array
    {
        return ['residual_threshold_m' => self::threshold($db)];
    }

    public static function saveSettings(PDO $db, array $body): array
    {
        $v = (float)($body['residual_threshold_m'] ?? 0);
        if ($v <= 0 || $v > 5) {
            throw new InvalidArgumentException('residual_threshold_m out of range');
        }
        $db->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute(['residual_threshold_m', (string)$v]);
        return self::settings($db);
    }

    private static function threshold(PDO $db): float
    {
        $st = $db->query("SELECT value FROM settings WHERE key = 'residual_threshold_m'")->fetch();
        return $st ? (float)$st['value'] : 0.15;
    }

    private static function stationId(PDO $db, string $slug): ?int
    {
        $st = $db->prepare('SELECT id FROM stations WHERE slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ? (int)$row['id'] : null;
    }
}
