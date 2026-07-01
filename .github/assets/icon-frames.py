#!/usr/bin/env python3
"""Generate per-frame square-icon SVGs for the animated plugin icon.

The coin flips on its vertical axis: its horizontal scale goes
1 -> 0 -> -1 -> 0 -> 1 (a full 360). The visible face swaps ETH<->BTC each time
the coin passes edge-on (scale crosses 0). A thin metal edge shows near scale 0.
"""
import math
import os
import sys

OUT = sys.argv[1] if len(sys.argv) > 1 else "frames"
N = int(sys.argv[2]) if len(sys.argv) > 2 else 36
os.makedirs(OUT, exist_ok=True)

# 256x256 canvas. Shadow mark top-left-ish, coin bottom-right, brand dark bg.
CANVAS = 256

def eth_face():
    # Ethereum diamond in dark on the coin.
    return (
        '<g fill="#0d0d0d">'
        '<path d="M0 -22 -12 2 0 9 12 2z" opacity="0.92"/>'
        '<path d="M0 12 -12 5 0 24 12 5z" opacity="0.78"/>'
        '</g>'
    )

def btc_face():
    return ('<text x="0" y="15" text-anchor="middle" '
            'font-family="Georgia,\'Times New Roman\',serif" '
            'font-size="44" font-weight="700" fill="#0d0d0d">&#8383;</text>')

def frame(i):
    t = i / N  # 0..1
    # Full rotation over the loop. angle 0..360.
    ang = t * 360.0
    sx = math.cos(math.radians(ang))  # 1 -> 0 -> -1 -> 0 -> 1
    # Which face: front (cos>=0 region around 0/360) shows ETH; back shows BTC.
    # Front visible while the coin normal faces us: ang in [-90,90] => cos of (ang) ... use rotation phase.
    show_eth = (ang < 90) or (ang > 270)
    face = eth_face() if show_eth else btc_face()
    absx = abs(sx)
    coin_r = 44
    # edge width grows as the coin turns side-on
    edge_w = 10 + (1 - absx) * 6

    svg = f'''<svg xmlns="http://www.w3.org/2000/svg" width="{CANVAS}" height="{CANVAS}" viewBox="0 0 {CANVAS} {CANVAS}">
  <defs>
    <radialGradient id="bg" cx="30%" cy="22%" r="95%">
      <stop offset="0%" stop-color="#153a1f"/>
      <stop offset="55%" stop-color="#0c0c0c"/>
      <stop offset="100%" stop-color="#060606"/>
    </radialGradient>
    <linearGradient id="coinFace" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#c2f394"/>
      <stop offset="52%" stop-color="#8fd468"/>
      <stop offset="100%" stop-color="#5aa63c"/>
    </linearGradient>
    <linearGradient id="edge" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#4f8f33"/>
      <stop offset="100%" stop-color="#2f5a1f"/>
    </linearGradient>
    <clipPath id="rc"><rect width="{CANVAS}" height="{CANVAS}" rx="46"/></clipPath>
  </defs>
  <g clip-path="url(#rc)">
    <rect width="{CANVAS}" height="{CANVAS}" fill="url(#bg)"/>
    <!-- faint hex lattice -->
    <g stroke="#8fd468" stroke-opacity="0.07" fill="none" stroke-width="3">
      <path d="M210 40 236 55v30l-26 15-26-15V55z"/>
      <path d="M40 200 62 213v26l-22 13-22-13v-26z"/>
    </g>

    <!-- Shadow hexagon + hooded mark, upper-left -->
    <g transform="translate(30 26) scale(2.05)">
      <path d="M20 1.5 37 11.25v19.5L20 40.5 3 30.75v-19.5z" fill="#0d0d0d" stroke="#8fd468" stroke-width="2.2" stroke-linejoin="round"/>
      <path d="M20 11c-4.4 0-7.8 3.3-7.8 8 0 2.6 1.1 4.7 2.6 6.2-1.7 1.1-2.9 2.7-3.3 4.8h17c-.4-2.1-1.6-3.7-3.3-4.8 1.5-1.5 2.6-3.6 2.6-6.2 0-4.7-3.4-8-7.8-8z" fill="#ededed"/>
      <ellipse cx="20" cy="19.5" rx="3.6" ry="4.2" fill="#0d0d0d"/>
    </g>

    <!-- Flipping coin, lower-right -->
    <g transform="translate(168 172)">
      <ellipse cx="0" cy="54" rx="{coin_r*absx*0.8+8:.1f}" ry="8" fill="#000" opacity="0.4"/>
      <g transform="scale({sx:.4f} 1)">
        <rect x="{-edge_w/2:.1f}" y="{-coin_r}" width="{edge_w:.1f}" height="{coin_r*2}" rx="{edge_w/2:.1f}" fill="url(#edge)"/>
      </g>
      <g transform="scale({absx:.4f} 1)" opacity="{min(1.0, absx*3):.3f}">
        <circle r="{coin_r}" fill="url(#coinFace)" stroke="#3e7a26" stroke-width="2.2"/>
        <circle r="{coin_r-6}" fill="none" stroke="#0d0d0d" stroke-opacity="0.16" stroke-width="1.6"/>
        {face}
      </g>
    </g>
  </g>
</svg>'''
    with open(f"{OUT}/frame_{i:03d}.svg", "w") as fh:
        fh.write(svg)

for i in range(N):
    frame(i)
print(f"wrote {N} frames to {OUT}/")
