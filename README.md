# 09-tideharm（潮谐）

港口潮位调和预报台。用分潮振幅与迟角合成潮位过程线，对照实测点看残差。

## 启动

```bash
docker compose up --build
```

| 入口 | 地址 |
| --- | --- |
| 前端 | http://localhost:3800 |
| API | http://localhost:8800 |

## 主链

选港口站 → 维护分潮 → 合成预报曲线 → 对照实测看残差表。

双站同窗对照页（`/compare`）选两个不同港口站，在同一时刻网格上返回两站预报点列与潮位差序列，
并同屏给出两站最大绝对残差与超阈标志。接口 `GET /api/forecast-diff?station_a=&station_b=&hours=&step_min=`：
两站时刻网格逐点对齐，不一致返回 400；未知站 404；相同站、时长越界（1–168h）、步长越界（5–120min）返回 400。

## 技术栈

PHP 8 + SQLite；Vue 3 + Vite。
