<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { getJSON } from '../api'

type Station = { slug: string; name: string }
type Pt = { t_hours: number; level_m: number }
type Df = { t_hours: number; diff_m: number }
type Summary = {
  slug: string
  name: string
  threshold_m: number
  max_abs_residual_m: number
  over: boolean
}
type DiffResp = {
  hours: number
  step_min: number
  station_a: Summary
  station_b: Summary
  points_a: Pt[]
  points_b: Pt[]
  diff: Df[]
}

const COLOR_A = '#3987e5'
const COLOR_B = '#d95926'
const COLOR_D = '#199e70'

const W = 720
const H = 250
const PAD = { l: 48, r: 72, t: 14, b: 28 }

const stations = ref<Station[]>([])
const slugA = ref('harbor-a')
const slugB = ref('harbor-b')
const hours = ref(48)
const stepMin = ref(30)
const data = ref<DiffResp | null>(null)
const err = ref('')
const loading = ref(false)
const hoverIdx = ref<number | null>(null)
const tipX = ref(0)
const tipY = ref(0)

async function loadStations() {
  const d = await getJSON<{ items: Station[] }>('/api/stations')
  stations.value = d.items
  if (!stations.value.some((s) => s.slug === slugA.value)) slugA.value = d.items[0]?.slug ?? ''
  if (!stations.value.some((s) => s.slug === slugB.value)) slugB.value = d.items[1]?.slug ?? d.items[0]?.slug ?? ''
}

async function load() {
  err.value = ''
  if (!slugA.value || !slugB.value) return
  if (slugA.value === slugB.value) {
    err.value = '请选择两个不同的港口站'
    data.value = null
    return
  }
  loading.value = true
  try {
    const q = `station_a=${slugA.value}&station_b=${slugB.value}&hours=${hours.value}&step_min=${stepMin.value}`
    data.value = await getJSON<DiffResp>(`/api/forecast-diff?${q}`)
  } catch (e) {
    data.value = null
    err.value = String(e)
  } finally {
    loading.value = false
  }
}

function scales(ys: number[]) {
  const pts = data.value
  if (!pts) return null
  const xs = pts.diff.map((p) => p.t_hours)
  const minX = Math.min(...xs)
  const maxX = Math.max(...xs)
  const lo = Math.min(...ys)
  const hi = Math.max(...ys)
  const padV = (hi - lo) * 0.08 || 0.5
  const minY = lo - padV
  const maxY = hi + padV
  const sx = (x: number) => PAD.l + ((x - minX) / (maxX - minX || 1)) * (W - PAD.l - PAD.r)
  const sy = (y: number) => H - PAD.b - ((y - minY) / (maxY - minY || 1)) * (H - PAD.t - PAD.b)
  return { sx, sy, minY, maxY }
}

function linePath(points: { t_hours: number; v: number }[], sc: NonNullable<ReturnType<typeof scales>>) {
  return points.map((p, i) => `${i ? 'L' : 'M'}${sc.sx(p.t_hours).toFixed(2)},${sc.sy(p.v).toFixed(2)}`).join(' ')
}

const levelSc = computed(() => {
  if (!data.value) return null
  return scales([...data.value.points_a.map((p) => p.level_m), ...data.value.points_b.map((p) => p.level_m)])
})
const pathA = computed(() => {
  const sc = levelSc.value
  return sc && data.value ? linePath(data.value.points_a.map((p) => ({ t_hours: p.t_hours, v: p.level_m })), sc) : ''
})
const pathB = computed(() => {
  const sc = levelSc.value
  return sc && data.value ? linePath(data.value.points_b.map((p) => ({ t_hours: p.t_hours, v: p.level_m })), sc) : ''
})

const diffSc = computed(() => {
  if (!data.value) return null
  const ys = data.value.diff.map((p) => p.diff_m)
  const sc = scales(ys)
  if (!sc) return null
  // 差分图以 0 为对称中心
  const m = Math.max(Math.abs(sc.minY), Math.abs(sc.maxY))
  return scales([-m, m])
})
const pathD = computed(() => {
  const sc = diffSc.value
  return sc && data.value ? linePath(data.value.diff.map((p) => ({ t_hours: p.t_hours, v: p.diff_m })), sc) : ''
})

function ticks(sc: NonNullable<ReturnType<typeof scales>>) {
  const out: { y: number; v: number }[] = []
  for (let i = 0; i <= 4; i++) {
    const v = sc.minY + ((sc.maxY - sc.minY) * i) / 4
    out.push({ y: sc.sy(v), v })
  }
  return out
}
const levelTicks = computed(() => (levelSc.value ? ticks(levelSc.value) : []))
const diffTicks = computed(() => (diffSc.value ? ticks(diffSc.value) : []))
const xTicks = computed(() => {
  if (!data.value || !levelSc.value) return []
  const sc = levelSc.value
  const last = data.value.diff[data.value.diff.length - 1].t_hours
  const step = last <= 24 ? 6 : last <= 72 ? 12 : 24
  const out: { x: number; v: number }[] = []
  for (let t = 0; t <= last + 1e-9; t += step) {
    out.push({ x: sc.sx(t), v: t })
  }
  return out
})

const endA = computed(() => {
  const sc = levelSc.value
  const p = data.value?.points_a.slice(-1)[0]
  return sc && p ? { x: sc.sx(p.t_hours), y: sc.sy(p.level_m) } : null
})
const endB = computed(() => {
  const sc = levelSc.value
  const p = data.value?.points_b.slice(-1)[0]
  return sc && p ? { x: sc.sx(p.t_hours), y: sc.sy(p.level_m) } : null
})

// ── hover 十字线与提示框（两图共享同一时间索引）────────────────────────
function onMove(ev: MouseEvent) {
  if (!data.value || !levelSc.value) return
  const svg = (ev.target as Element).closest('svg')
  const wrap = (ev.currentTarget as HTMLElement).getBoundingClientRect()
  if (!svg) return
  const rect = svg.getBoundingClientRect()
  const xView = ((ev.clientX - rect.left) / rect.width) * W
  const sc = levelSc.value
  let best = 0
  let bestD = Infinity
  data.value.diff.forEach((p, i) => {
    const d = Math.abs(sc.sx(p.t_hours) - xView)
    if (d < bestD) {
      bestD = d
      best = i
    }
  })
  hoverIdx.value = best
  tipX.value = ev.clientX - wrap.left
  tipY.value = ev.clientY - wrap.top
}
function onLeave() {
  hoverIdx.value = null
}
const hoverPt = computed(() => {
  if (hoverIdx.value === null || !data.value || !levelSc.value || !diffSc.value) return null
  const i = hoverIdx.value
  const pa = data.value.points_a[i]
  const pb = data.value.points_b[i]
  const pd = data.value.diff[i]
  return {
    i,
    t: pa.t_hours,
    a: pa.level_m,
    b: pb.level_m,
    d: pd.diff_m,
    xa: levelSc.value.sx(pa.t_hours),
    ya: levelSc.value.sy(pa.level_m),
    yb: levelSc.value.sy(pb.level_m),
    xd: diffSc.value.sx(pd.t_hours),
    yd: diffSc.value.sy(pd.diff_m),
  }
})
const tipFlip = computed(() => tipX.value > 360)

function onFocus() {
  if (slugA.value && slugB.value) load().catch(() => {})
}

onMounted(async () => {
  try {
    await loadStations()
    await load()
  } catch (e) {
    err.value = String(e)
  }
  window.addEventListener('focus', onFocus)
})
onBeforeUnmount(() => window.removeEventListener('focus', onFocus))
watch([slugA, slugB, hours, stepMin], () => load().catch((e) => (err.value = String(e))))
</script>

<template>
  <div class="page">
    <h1>双站同窗对照</h1>
    <p class="lead">东港站与西湾站同一时间网格上的预报双曲线与潮位差；两站残差摘要同屏显示。</p>
    <p v-if="err" class="err">{{ err }}</p>

    <div class="panel" style="margin-bottom: 12px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center">
      <label>甲站
        <select v-model="slugA">
          <option v-for="s in stations" :key="s.slug" :value="s.slug" :disabled="s.slug === slugB">{{ s.name }}</option>
        </select>
      </label>
      <label>乙站
        <select v-model="slugB">
          <option v-for="s in stations" :key="s.slug" :value="s.slug" :disabled="s.slug === slugA">{{ s.name }}</option>
        </select>
      </label>
      <label>时长 h
        <input type="number" min="1" max="168" v-model.number="hours" style="width: 80px" />
      </label>
      <label>步长 min
        <select v-model.number="stepMin">
          <option :value="5">5</option>
          <option :value="10">10</option>
          <option :value="15">15</option>
          <option :value="30">30</option>
          <option :value="60">60</option>
          <option :value="120">120</option>
        </select>
      </label>
      <button @click="load" :disabled="loading">{{ loading ? '查询中…' : '查询/刷新' }}</button>
    </div>

    <template v-if="data">
      <div class="cards">
        <div class="panel card" v-for="(s, k) in [data.station_a, data.station_b]" :key="s.slug">
          <div class="card-head">
            <span class="chip" :style="{ background: k === 0 ? COLOR_A : COLOR_B }"></span>
            <strong>{{ s.name }}</strong>
          </div>
          <div class="card-row">最大 |残差|：<b>{{ s.max_abs_residual_m }}</b> m</div>
          <div class="card-row">当前阈值：{{ s.threshold_m }} m</div>
          <div class="card-row">
            状态：<span :class="s.over ? 'badge-bad' : 'badge-ok'">{{ s.over ? '超阈' : '正常' }}</span>
          </div>
          <div class="card-row"><RouterLink :to="`/stations/${s.slug}/residuals`">残差明细 →</RouterLink></div>
        </div>
      </div>

      <div class="plot-wrap" @mousemove="onMove" @mouseleave="onLeave">
        <div class="panel plot" style="position: relative">
          <div class="legend">
            <span><i class="chip" :style="{ background: COLOR_A }"></i>{{ data.station_a.name }} 预报</span>
            <span><i class="chip" :style="{ background: COLOR_B }"></i>{{ data.station_b.name }} 预报</span>
          </div>
          <svg :viewBox="`0 0 ${W} ${H}`" width="100%" :height="H">
            <line v-for="(t, i) in levelTicks" :key="'g' + i" :x1="PAD.l" :x2="W - PAD.r" :y1="t.y" :y2="t.y"
                  class="grid" />
            <text v-for="(t, i) in levelTicks" :key="'ty' + i" :x="PAD.l - 6" :y="t.y + 3"
                  class="axis-label" text-anchor="end">{{ t.v.toFixed(1) }}</text>
            <text v-for="t in xTicks" :key="'tx' + t.v" :x="t.x" :y="H - PAD.b + 16" class="axis-label" text-anchor="middle">
              {{ t.v }}h
            </text>
            <path :d="pathA" fill="none" :stroke="COLOR_A" stroke-width="2" />
            <path :d="pathB" fill="none" :stroke="COLOR_B" stroke-width="2" />
            <text v-if="endA" :x="endA.x + 6" :y="endA.y + 4" class="end-label" :fill="COLOR_A">
              {{ data.station_a.name }}
            </text>
            <text v-if="endB" :x="endB.x + 6" :y="endB.y + 4" class="end-label" :fill="COLOR_B">
              {{ data.station_b.name }}
            </text>
            <g v-if="hoverPt">
              <line :x1="hoverPt.xa" :x2="hoverPt.xa" :y1="PAD.t" :y2="H - PAD.b" class="cross" />
              <circle :cx="hoverPt.xa" :cy="hoverPt.ya" r="4" :fill="COLOR_A" stroke="#081820" stroke-width="2" />
              <circle :cx="hoverPt.xa" :cy="hoverPt.yb" r="4" :fill="COLOR_B" stroke="#081820" stroke-width="2" />
            </g>
          </svg>
        </div>

        <div class="panel plot" style="position: relative; margin-top: 12px">
          <div class="legend"><span><i class="chip" :style="{ background: COLOR_D }"></i>潮位差（甲 − 乙）</span></div>
          <svg :viewBox="`0 0 ${W} ${H}`" width="100%" :height="H">
            <line v-for="(t, i) in diffTicks" :key="'g' + i" :x1="PAD.l" :x2="W - PAD.r" :y1="t.y" :y2="t.y" class="grid" />
            <line v-if="diffSc" :x1="PAD.l" :x2="W - PAD.r" :y1="diffSc.sy(0)" :y2="diffSc.sy(0)" class="zero" />
            <text v-for="(t, i) in diffTicks" :key="'ty' + i" :x="PAD.l - 6" :y="t.y + 3"
                  class="axis-label" text-anchor="end">{{ t.v.toFixed(2) }}</text>
            <text v-for="t in xTicks" :key="'tx' + t.v" :x="t.x" :y="H - PAD.b + 16" class="axis-label" text-anchor="middle">
              {{ t.v }}h
            </text>
            <path :d="pathD" fill="none" :stroke="COLOR_D" stroke-width="2" />
            <g v-if="hoverPt">
              <line :x1="hoverPt.xd" :x2="hoverPt.xd" :y1="PAD.t" :y2="H - PAD.b" class="cross" />
              <circle :cx="hoverPt.xd" :cy="hoverPt.yd" r="4" :fill="COLOR_D" stroke="#081820" stroke-width="2" />
            </g>
          </svg>
        </div>

        <div v-if="hoverPt" class="tip" :class="{ flip: tipFlip }"
             :style="{ left: tipX + 'px', top: tipY + 'px' }">
          <div>t = {{ hoverPt.t }} h</div>
          <div><i class="chip" :style="{ background: COLOR_A }"></i>{{ data.station_a.name }}：{{ hoverPt.a }} m</div>
          <div><i class="chip" :style="{ background: COLOR_B }"></i>{{ data.station_b.name }}：{{ hoverPt.b }} m</div>
          <div><i class="chip" :style="{ background: COLOR_D }"></i>潮位差：{{ hoverPt.d }} m</div>
        </div>
      </div>

      <details class="panel" style="margin-top: 12px">
        <summary>数据表（{{ data.diff.length }} 个对齐时刻）</summary>
        <table style="margin-top: 10px">
          <thead>
            <tr><th>t/h</th><th>{{ data.station_a.name }}/m</th><th>{{ data.station_b.name }}/m</th><th>差/m</th></tr>
          </thead>
          <tbody>
            <tr v-for="(d, i) in data.diff" :key="i">
              <td>{{ d.t_hours }}</td>
              <td>{{ data.points_a[i].level_m }}</td>
              <td>{{ data.points_b[i].level_m }}</td>
              <td>{{ d.diff_m }}</td>
            </tr>
          </tbody>
        </table>
      </details>
    </template>
  </div>
</template>

<style scoped>
.plot-wrap {
  position: relative;
}
.plot-wrap .plot {
  height: auto;
}
.cards {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
  margin-bottom: 12px;
}
.card-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}
.card-row {
  padding: 2px 0;
  color: var(--muted);
}
.card-row b {
  color: var(--text);
}
.chip {
  display: inline-block;
  width: 12px;
  height: 12px;
  border-radius: 3px;
}
.legend {
  display: flex;
  gap: 18px;
  padding: 2px 4px 0;
  color: var(--muted);
  font-size: 13px;
}
.legend .chip {
  width: 16px;
  height: 3px;
  border-radius: 2px;
  margin-right: 6px;
  vertical-align: middle;
}
.grid {
  stroke: var(--line);
  stroke-width: 1;
  opacity: 0.55;
}
.zero {
  stroke: var(--muted);
  stroke-width: 1.2;
  stroke-dasharray: 5 4;
}
.axis-label {
  fill: var(--muted);
  font-size: 11px;
}
.end-label {
  font-size: 12px;
}
.cross {
  stroke: var(--muted);
  stroke-width: 1;
  stroke-dasharray: 3 3;
  opacity: 0.8;
}
.tip {
  position: absolute;
  pointer-events: none;
  transform: translate(12px, -50%);
  background: rgba(8, 24, 32, 0.94);
  border: 1px solid var(--line);
  border-radius: 6px;
  padding: 8px 10px;
  font-size: 12px;
  line-height: 1.8;
  white-space: nowrap;
  z-index: 5;
}
.tip .chip {
  width: 10px;
  height: 3px;
  border-radius: 2px;
  margin-right: 6px;
}
.tip.flip {
  transform: translate(calc(-100% - 12px), -50%);
}
@media (max-width: 720px) {
  .cards { grid-template-columns: 1fr; }
}
</style>
