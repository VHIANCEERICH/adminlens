import html
import io
import json
import math
import sys
from pathlib import Path

# Force UTF-8 output — prevents charmap codec crash on Windows
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

PALETTE = [
    '#378ADD', '#1D9E75', '#BA7517', '#A32D2D',
    '#534AB7', '#D85A30', '#993556', '#639922',
    '#185FA5', '#0F6E56',
]


def load_payload(arg: str) -> dict:
    path = Path(arg)
    if path.is_file():
        with path.open('r', encoding='utf-8-sig') as f:
            return json.load(f)
    return json.loads(arg)


def esc(v) -> str:
    return html.escape(str(v), quote=True)


def short(v: str, n: int = 18) -> str:
    v = str(v)
    return v if len(v) <= n else v[:max(0, n - 3)] + '...'


def _ranking_items(products: list) -> list:
    return sorted(products, key=lambda p: int(p.get('units_sold', 0)), reverse=True)


def _stock_items(products: list) -> list:
    return list(products)


def _value_items(products: list) -> list:
    return sorted(
        products,
        key=lambda p: float(p.get('price', 0)) * int(p.get('stock_on_hand', 0)),
        reverse=True,
    )


# ---------------------------------------------------------------------------
# Individual product performance card (single product, chart_type=product)
# ---------------------------------------------------------------------------

def render_product_chart(product: dict) -> str:
    sku     = str(product.get('sku', ''))
    name    = str(product.get('product_name', 'Unnamed'))
    stock   = int(product.get('stock_on_hand', 0))
    reorder = int(product.get('reorder_point', 0))
    sold    = int(product.get('units_sold', 0))
    rank    = str(product.get('rank', 'normal'))

    color = '#378ADD'
    if rank == 'best':    color = '#1D9E75'
    elif rank == 'least': color = '#A32D2D'

    mx  = max(1, sold, stock, reorder)
    sw  = max(14, (sold   / mx) * 520)
    stw = max(14, (stock  / mx) * 520)
    rx  = (reorder / mx) * 520

    return (
        f'<div style="font-family:system-ui,sans-serif;background:#1e293b;border:1px solid #334155;'
        f'border-radius:12px;padding:20px 24px;margin:8px 0">'
        f'<div style="font-size:16px;font-weight:600;color:#f1f5f9">{esc(name)}</div>'
        f'<div style="font-size:12px;color:#64748b;margin-bottom:16px">SKU {esc(sku)}</div>'
        f'<svg viewBox="0 0 560 110" xmlns="http://www.w3.org/2000/svg" style="width:100%;display:block">'
        f'<text x="0" y="14" font-size="10" fill="#64748b">Units sold</text>'
        f'<rect x="0" y="20" width="{sw:.1f}" height="20" rx="10" fill="{color}"/>'
        f'<text x="{sw+6:.1f}" y="33" font-size="11" font-weight="600" fill="{color}">{sold:,}</text>'
        f'<text x="0" y="60" font-size="10" fill="#64748b">Stock on hand</text>'
        f'<rect x="0" y="66" width="{stw:.1f}" height="20" rx="10" fill="#60a5fa"/>'
        f'<text x="{stw+6:.1f}" y="79" font-size="11" font-weight="600" fill="#60a5fa">{stock:,}</text>'
        f'<line x1="{rx:.1f}" y1="14" x2="{rx:.1f}" y2="96" stroke="#ef4444" stroke-width="2" stroke-dasharray="4,3"/>'
        f'<text x="{rx:.1f}" y="106" font-size="10" fill="#ef4444" text-anchor="middle">Reorder {reorder:,}</text>'
        f'</svg></div>'
    )


# ---------------------------------------------------------------------------
# SVG: Horizontal bar chart — Sales Ranking
# ---------------------------------------------------------------------------

def _ranking_svg(products: list) -> str:
    items = _ranking_items(products)
    if not items:
        return '<svg class="chart-svg chart-svg--ranking" viewBox="0 0 400 60" xmlns="http://www.w3.org/2000/svg"><text x="20" y="35" font-size="13" fill="#64748b">No data</text></svg>'

    top   = max(1, max(int(p.get('units_sold', 0)) for p in items))
    row_h = 56
    left  = 220
    bar_w = 620
    pad_t = 24
    pad_b = 40
    n     = len(items)
    svg_h = pad_t + n * row_h + pad_b
    svg_w = left + bar_w + 70

    grid = ''
    for s in range(6):
        gx   = left + (s / 5) * bar_w
        gval = int((s / 5) * top)
        grid += (
            f'<line x1="{gx:.1f}" y1="{pad_t}" x2="{gx:.1f}" y2="{pad_t + n * row_h}" '
            f'stroke="#334155" stroke-width="1"/>'
            f'<text x="{gx:.1f}" y="{pad_t + n * row_h + 18}" '
            f'font-size="12" fill="#64748b" text-anchor="middle">{gval:,}</text>'
        )

    bars = ''
    for idx, p in enumerate(items):
        val   = int(p.get('units_sold', 0))
        label = short(str(p.get('product_name', 'Unnamed')), 20)
        color = PALETTE[idx % len(PALETTE)]
        cy    = pad_t + idx * row_h
        bw    = max(4, (val / top) * bar_w)
        mid   = cy + row_h // 2

        bars += (
            f'<text x="{left - 12}" y="{mid + 5}" font-size="16" font-weight="600" fill="#1e293b" text-anchor="end">{esc(label)}</text>'
            f'<rect x="{left}" y="{cy + 10}" width="{bw:.1f}" height="{row_h - 20}" rx="6" fill="{color}"/>'
            f'<text x="{left + bw + 10:.1f}" y="{mid + 5}" font-size="14" font-weight="700" fill="{color}">{val:,}</text>'
        )

    return (
        f'<svg class="chart-svg chart-svg--ranking" viewBox="0 0 {svg_w} {svg_h}" xmlns="http://www.w3.org/2000/svg" '
        f'style="width:100%;display:block;min-width:700px">'
        f'{grid}{bars}'
        f'<line x1="{left}" y1="{pad_t}" x2="{left}" y2="{pad_t + n * row_h}" stroke="#475569" stroke-width="1"/>'
        f'</svg>'
    )


# ---------------------------------------------------------------------------
# SVG: Donut chart — Stock Share
# ---------------------------------------------------------------------------

def _stock_svg(products: list) -> str:
    items = _stock_items(products)
    total = sum(max(0, int(p.get('stock_on_hand', 0))) for p in items)
    if total <= 0:
        return '<svg class="chart-svg chart-svg--pie" viewBox="0 0 400 60" xmlns="http://www.w3.org/2000/svg"><text x="20" y="35" font-size="13" fill="#64748b">No stock data</text></svg>'

    cx, cy, r_out, r_in = 220, 190, 160, 92

    def arc(start_deg, end_deg, color):
        s  = math.radians(start_deg - 90)
        e  = math.radians(end_deg   - 90)
        x1 = cx + r_out * math.cos(s);  y1 = cy + r_out * math.sin(s)
        x2 = cx + r_out * math.cos(e);  y2 = cy + r_out * math.sin(e)
        i1 = cx + r_in  * math.cos(e);  j1 = cy + r_in  * math.sin(e)
        i2 = cx + r_in  * math.cos(s);  j2 = cy + r_in  * math.sin(s)
        lg = 1 if (end_deg - start_deg) > 180 else 0
        return (
            f'<path d="M {x1:.2f},{y1:.2f} A {r_out},{r_out} 0 {lg},1 {x2:.2f},{y2:.2f} '
            f'L {i1:.2f},{j1:.2f} A {r_in},{r_in} 0 {lg},0 {i2:.2f},{j2:.2f} Z" '
            f'fill="{color}" stroke="#0f172a" stroke-width="2"/>'
        )

    paths  = ''
    offset = 0.0
    for idx, p in enumerate(items):
        stock = max(0, int(p.get('stock_on_hand', 0)))
        deg   = (stock / total) * 360
        color = PALETTE[idx % len(PALETTE)]
        end   = min(offset + deg, 359.999)
        paths += arc(offset, end, color)
        offset += deg

    center = (
        f'<circle cx="{cx}" cy="{cy}" r="{r_in - 2}" fill="#0f172a"/>'
        f'<text x="{cx}" y="{cy - 10}" font-size="30" font-weight="700" fill="#f1f5f9" text-anchor="middle">{total:,}</text>'
        f'<text x="{cx}" y="{cy + 18}" font-size="14" fill="#94a3b8" text-anchor="middle">total units</text>'
    )

    svg_h = cy + r_out + 20
    return (
        f'<div class="pie-chart-wrap">'
        f'<svg class="chart-svg chart-svg--pie" viewBox="0 0 {cx + r_out + 20} {svg_h}" xmlns="http://www.w3.org/2000/svg" '
        f'style="display:block;margin:0 auto;width:100%;height:100%">'
        f'{paths}{center}</svg>'
        f'</div>'
    )


# ---------------------------------------------------------------------------
# SVG: Line chart — Inventory Value
# ---------------------------------------------------------------------------

def _value_svg(products: list) -> str:
    items = _value_items(products)
    if not items:
        return '<svg class="chart-svg chart-svg--value" viewBox="0 0 400 60" xmlns="http://www.w3.org/2000/svg"><text x="20" y="35" font-size="13" fill="#64748b">No data</text></svg>'

    values = [float(p.get('price', 0)) * int(p.get('stock_on_hand', 0)) for p in items]
    mx     = max(1.0, max(values))
    n      = len(items)
    pl, pr = 96, 40
    pt, pb = 26, 86
    w, h   = 840, 340
    pw     = w - pl - pr
    ph     = h - pt - pb

    def gx(i): return pl if n == 1 else pl + (i / (n - 1)) * pw
    def gy(v): return pt + ph - (v / mx) * ph

    pts      = [(gx(i), gy(v)) for i, v in enumerate(values)]
    polyline = ' '.join(f'{x:.1f},{y:.1f}' for x, y in pts)
    area     = polyline + f' {pts[-1][0]:.1f},{pt+ph} {pts[0][0]:.1f},{pt+ph}'

    grid = ''
    for s in range(1, 5):
        gy2  = pt + ph - (s / 4) * ph
        gval = (s / 4) * mx
        lbl  = f'P{gval/1000:.0f}K' if gval >= 1000 else f'P{gval:.0f}'
        grid += (
            f'<line x1="{pl}" y1="{gy2:.1f}" x2="{w-pr}" y2="{gy2:.1f}" stroke="#334155" stroke-width="1"/>'
            f'<text x="{pl-8}" y="{gy2+5:.1f}" font-size="12" fill="#64748b" text-anchor="end">{lbl}</text>'
        )

    dots = ''
    for i, ((x, y), v) in enumerate(zip(pts, values)):
        lbl   = f'P{v/1000:.1f}K' if v >= 1000 else f'P{v:.0f}'
        name  = short(str(items[i].get('product_name', '')), 12)
        color = PALETTE[i % len(PALETTE)]
        dots += (
            f'<circle cx="{x:.1f}" cy="{y:.1f}" r="6" fill="{color}" stroke="#0f172a" stroke-width="2"/>'
            f'<text x="{x:.1f}" y="{max(pt+14, y-10):.1f}" font-size="11" font-weight="700" '
            f'fill="{color}" text-anchor="middle">{lbl}</text>'
            f'<text x="{x:.1f}" y="{pt+ph+30}" font-size="11" fill="#334155" text-anchor="end" '
            f'transform="rotate(-32 {x:.1f},{pt+ph+30})">{esc(name)}</text>'
        )

    return (
        f'<svg class="chart-svg chart-svg--value" viewBox="0 0 {w} {h}" xmlns="http://www.w3.org/2000/svg" '
        f'style="width:100%;display:block;min-width:760px">'
        f'<defs><linearGradient id="vg" x1="0" y1="0" x2="0" y2="1">'
        f'<stop offset="0%" stop-color="#ef4444" stop-opacity="0.25"/>'
        f'<stop offset="100%" stop-color="#ef4444" stop-opacity="0.02"/>'
        f'</linearGradient></defs>'
        f'{grid}'
        f'<line x1="{pl}" y1="{pt}" x2="{pl}" y2="{pt+ph}" stroke="#475569" stroke-width="1"/>'
        f'<line x1="{pl}" y1="{pt+ph}" x2="{w-pr}" y2="{pt+ph}" stroke="#475569" stroke-width="1"/>'
        f'<polygon points="{area}" fill="url(#vg)"/>'
        f'<polyline points="{polyline}" fill="none" stroke="#ef4444" stroke-width="3" '
        f'stroke-linejoin="round" stroke-linecap="round"/>'
        f'{dots}</svg>'
    )


# ---------------------------------------------------------------------------
# Legend rows — shown at the bottom of every chart view
# Color dot | Product name (bold) | SKU · sold · stock · price
# ---------------------------------------------------------------------------

def _legend_rows(items: list) -> str:
    rows  = '<div class="line-legend">'
    for idx, p in enumerate(items):
        color = PALETTE[idx % len(PALETTE)]
        name  = esc(str(p.get('product_name', 'Unnamed')))
        sku   = esc(str(p.get('sku', '')))
        sold  = int(p.get('units_sold', 0))
        stock = int(p.get('stock_on_hand', 0))
        price = float(p.get('price', 0))

        rows += (
            f'<div class="line-legend__item">'
            f'<div class="line-legend__body">'
            f'<div class="line-legend__head">'
            f'<span class="line-legend__swatch" style="background:{color}"></span>'
            f'<div class="line-legend__name">{name}</div>'
            f'</div>'
            f'<div class="line-legend__meta">'
            f'{sku} &middot; {sold:,} sold &middot; {stock:,} in stock &middot; '
            f'&#x20B1;{price:,.0f}</div>'
            f'</div>'
            f'</div>'
        )
    rows += '</div>'
    return rows


# ---------------------------------------------------------------------------
# Full tabbed dashboard — chart_type = "dashboard"
# Three tab buttons at top; one chart shown at a time; legend rows at bottom
# ---------------------------------------------------------------------------

def render_dashboard(products: list) -> str:
    ranking_svg = _ranking_svg(products)
    stock_svg   = _stock_svg(products)
    value_svg   = _value_svg(products)
    ranking_legend = _legend_rows(_ranking_items(products))
    stock_legend   = _legend_rows(_stock_items(products))
    value_legend   = _legend_rows(_value_items(products))

    uid = 'alc'   # prefix — change if embedding multiple dashboards

    BTN_ACTIVE = (
        'padding:8px 20px;border-radius:8px;border:1px solid #475569;'
        'background:#1e293b;color:#f1f5f9;font-size:13px;font-weight:600;cursor:pointer'
    )
    BTN_IDLE = (
        'padding:8px 20px;border-radius:8px;border:1px solid #334155;'
        'background:transparent;color:#94a3b8;font-size:13px;font-weight:600;cursor:pointer'
    )

    return (
        f'<div id="{uid}" class="chart-output chart-output--dashboard" style="font-family:system-ui,sans-serif;">'

        # ---- Tab buttons ----
        f'<div class="chart-tabs" style="display:flex;gap:8px;padding:16px 16px 0">'
        f'<button id="{uid}_btn_r" onclick="{uid}_sw(\'r\',this)" style="{BTN_ACTIVE}">Sales ranking</button>'
        f'<button id="{uid}_btn_s" onclick="{uid}_sw(\'s\',this)" style="{BTN_IDLE}">Stock share</button>'
        f'<button id="{uid}_btn_v" onclick="{uid}_sw(\'v\',this)" style="{BTN_IDLE}">Inventory value</button>'
        f'</div>'

        f'<div class="chart-stage chart-stage--split">'

        # Ranking
        f'<div id="{uid}_r" class="chart-pane">'
        f'<div class="chart-stage__main">'
        f'<div style="font-size:18px;font-weight:700;color:#172554;margin-bottom:4px">Sales ranking</div>'
        f'<div style="font-size:14px;color:#64748b;margin-bottom:18px">Units sold &mdash; best seller to least purchased</div>'
        f'<div class="chart-scroll">{ranking_svg}</div>'
        f'</div>'
        f'<div class="chart-legend-shell chart-legend-shell--side">{ranking_legend}</div>'
        f'</div>'

        # Stock
        f'<div id="{uid}_s" class="chart-pane" style="display:none">'
        f'<div class="chart-stage__main">'
        f'<div style="font-size:18px;font-weight:700;color:#172554;margin-bottom:4px">Stock share</div>'
        f'<div style="font-size:14px;color:#64748b;margin-bottom:18px">Pie chart of current stock quantities</div>'
        f'<div class="chart-scroll">{stock_svg}</div>'
        f'</div>'
        f'<div class="chart-legend-shell chart-legend-shell--side">{stock_legend}</div>'
        f'</div>'

        # Value
        f'<div id="{uid}_v" class="chart-pane" style="display:none">'
        f'<div class="chart-stage__main">'
        f'<div style="font-size:18px;font-weight:700;color:#172554;margin-bottom:4px">Inventory value</div>'
        f'<div style="font-size:14px;color:#64748b;margin-bottom:18px">Line graph of inventory value per product</div>'
        f'<div class="chart-scroll">{value_svg}</div>'
        f'</div>'
        f'<div class="chart-legend-shell chart-legend-shell--side">{value_legend}</div>'
        f'</div>'

        f'</div>'

        f'</div>'  # end dashboard wrapper

        # ---- JS tab switcher ----
        f'<script>'
        f'(function(){{'
        f'  var uid="{uid}";'
        f'  var tabs=["r","s","v"];'
        f'  var ACTIVE="{BTN_ACTIVE.replace(chr(34), chr(39))}";'
        f'  var IDLE="{BTN_IDLE.replace(chr(34), chr(39))}";'
        f'  window[uid+"_sw"]=function(name,btn){{'
        f'    tabs.forEach(function(t){{'
        f'      document.getElementById(uid+"_"+t).style.display="none";'
        f'      document.getElementById(uid+"_btn_"+t).style.cssText=IDLE;'
        f'    }});'
        f'    document.getElementById(uid+"_"+name).style.display="block";'
        f'    btn.style.cssText=ACTIVE;'
        f'  }};'
        f'}})();'
        f'</script>'
    )


# ---------------------------------------------------------------------------
# Stand-alone chart renderers (chart_type = ranking / stock / value)
# ---------------------------------------------------------------------------

def _wrap(title: str, sub: str, body: str, legend: str) -> str:
    return (
        f'<div class="chart-output chart-output--single" style="font-family:system-ui,sans-serif;">'
        f'<div class="chart-stage chart-stage--split">'
        f'<div class="chart-pane" style="display:grid;grid-template-columns:minmax(0,1.7fr) minmax(300px,0.9fr);gap:20px;">'
        f'<div class="chart-stage__main">'
        f'<div style="font-size:18px;font-weight:700;color:#172554;margin-bottom:4px">{title}</div>'
        f'<div style="font-size:14px;color:#64748b;margin-bottom:18px">{sub}</div>'
        f'{body}'
        f'</div>'
        f'<div class="chart-legend-shell chart-legend-shell--side">{legend}</div>'
        f'</div>'
        f'</div>'
        f'</div>'
    )


def render_ranking_chart(products: list) -> str:
    return _wrap(
        'Sales ranking', 'Units sold &mdash; best seller to least purchased',
        f'<div style="overflow-x:auto">{_ranking_svg(products)}</div>',
        _legend_rows(_ranking_items(products))
    )


def render_stock_chart(products: list) -> str:
    return _wrap(
        'Stock share', 'Pie chart of current stock quantities',
        _stock_svg(products),
        _legend_rows(_stock_items(products))
    )


def render_value_chart(products: list) -> str:
    return _wrap(
        'Inventory value', 'Line graph of inventory value per product',
        f'<div style="overflow-x:auto">{_value_svg(products)}</div>',
        _legend_rows(_value_items(products))
    )


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> int:
    if len(sys.argv) < 2:
        print('Missing JSON payload.', file=sys.stderr)
        return 1

    try:
        payload    = load_payload(sys.argv[1])
        chart_type = str(payload.get('chart_type', 'product'))

        if chart_type == 'product':
            output = render_product_chart(payload)
        else:
            products = payload.get('products', [])
            if not isinstance(products, list):
                raise ValueError('products must be a list')

            if chart_type == 'ranking':
                output = render_ranking_chart(products)
            elif chart_type == 'stock':
                output = render_stock_chart(products)
            elif chart_type == 'value':
                output = render_value_chart(products)
            elif chart_type == 'dashboard':
                output = render_dashboard(products)
            else:
                raise ValueError(f'Unknown chart_type: {chart_type}')

        print(output)
        return 0

    except Exception as exc:
        print(f'Chart generation failed: {exc}', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
