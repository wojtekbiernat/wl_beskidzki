#!/usr/bin/env python3
"""
Generate verification code CSV files for badge purchases.
Run once: python3 generate_codes.py
Each file: code,is_used — 500 unique 8-character codes (a-z + 0-9)
"""
import random
import string
import csv
import os

BADGES   = ['WL_ZLOTA', 'WL_SREBRO', 'WL_BRAZ']
COUNT    = 500
CODE_LEN = 8
CHARS    = string.ascii_lowercase + string.digits
OUT_DIR  = os.path.join(os.path.dirname(__file__), 'codes')

os.makedirs(OUT_DIR, exist_ok=True)

for badge in BADGES:
    path = os.path.join(OUT_DIR, f'{badge}.csv')
    if os.path.exists(path):
        print(f'SKIP  {badge}.csv (already exists)')
        continue
    codes = set()
    while len(codes) < COUNT:
        codes.add(''.join(random.choices(CHARS, k=CODE_LEN)))
    with open(path, 'w', newline='', encoding='utf-8') as f:
        writer = csv.writer(f)
        writer.writerow(['code', 'is_used'])
        for code in sorted(codes):
            writer.writerow([code, 0])
    print(f'OK    {badge}.csv — {COUNT} codes')
