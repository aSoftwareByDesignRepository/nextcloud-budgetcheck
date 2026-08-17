#!/usr/bin/env python3
"""Build complete _quality_fixes_{lang}.json from formal catalog + sibling seeds."""
from __future__ import annotations

import json
import re
from pathlib import Path

L10N = Path(__file__).parent
APPS = L10N.parents[1]
LOCALES = ["de", "fr", "es", "da", "nl", "it", "pl", "sv", "nb", "pt_BR"]
SEED_APPS = [
    "dutycheck",
    "snackcheck",
    "inventorycheck",
    "audiocheck",
    "ticketcheck",
    "mobilitycheck",
    "maintenancecheck",
    "arbeitszeitcheck",
]

INFORMAL = {
    "de": re.compile(r"\b(du|dein|deine|deinen|deinem|deiner|dir|dich)\b", re.I),
    "fr": re.compile(r"\b(tu|ton|ta|tes|toi)\b", re.I),
    "es": re.compile(r"\b(tú|tu|te|contigo)\b", re.I),
    "da": re.compile(r"\b(du|din|dine|dit|dig)\b", re.I),
    "nb": re.compile(r"\b(du|din|dine|dit|deg)\b", re.I),
    "sv": re.compile(r"\b(du|din|dina|ditt|dig)\b", re.I),
    "nl": re.compile(r"\b(je|jij|jou|jouw)\b", re.I),
    "it": re.compile(r"\b(tu|tuo|tua|tuoi|tue|ti)\b", re.I),
    "pl": re.compile(r"\b(ty|twój|twoja|twoje|tobie|cię|ci)\b", re.I),
    "pt_BR": re.compile(r"\b(você|teu|tua|teus|tuas)\b", re.I),
}

en = json.loads((L10N / "en.json").read_text(encoding="utf-8"))["translations"]
catalog_path = L10N / "_formal_catalog.json"
catalog: dict[str, dict[str, str]] = {}
if catalog_path.exists():
    catalog = json.loads(catalog_path.read_text(encoding="utf-8"))


def load_trans(app: str, lang: str) -> dict[str, str]:
    path = APPS / app / "l10n" / f"{lang}.json"
    if not path.exists():
        return {}
    return json.loads(path.read_text(encoding="utf-8")).get("translations", {})


def load_quality_fixes(app: str, lang: str) -> dict[str, str]:
    path = APPS / app / "l10n" / f"_quality_fixes_{lang}.json"
    if not path.exists():
        return {}
    return json.loads(path.read_text(encoding="utf-8"))


def load_scandinavian() -> dict[str, dict[str, str]]:
    path = L10N / "formal_scandinavian_data.json"
    if not path.exists():
        return {}
    return json.loads(path.read_text(encoding="utf-8"))


def seeds(lang: str) -> dict[str, str]:
    out: dict[str, str] = {}
    for app in SEED_APPS:
        src = load_trans(app, lang)
        for k, ev in en.items():
            if k in src and src[k] != ev:
                if lang not in INFORMAL or not INFORMAL[lang].search(src[k]):
                    out[k] = src[k]
        qf = load_quality_fixes(app, lang)
        for k, v in qf.items():
            if not isinstance(v, str) or not v:
                continue
            if lang not in INFORMAL or not INFORMAL[lang].search(v):
                out[k] = v
    scan = load_scandinavian()
    if lang in ("da", "sv", "nb"):
        for k, langs in scan.items():
            if lang in langs:
                out[k] = langs[lang]
    cat = catalog.get(lang, {})
    for k, v in cat.items():
        if v:
            out[k] = v
    return out


def main() -> None:
    scan = load_scandinavian()
    for lang in LOCALES:
        fail_path = L10N / f"_fail_{lang}.json"
        if not fail_path.exists():
            continue
        fail = json.loads(fail_path.read_text(encoding="utf-8"))["all"]
        if not fail:
            (L10N / f"_quality_fixes_{lang}.json").write_text("{}\n", encoding="utf-8")
            print(f"{lang}: 0 fixes")
            continue
        seed = seeds(lang)
        fixes: dict[str, str] = {}
        missing: list[str] = []
        for key in fail:
            if key in seed:
                fixes[key] = seed[key]
            else:
                missing.append(key)
        if missing:
            print(f"{lang}: WARNING missing {len(missing)} keys")
            (L10N / f"_missing_{lang}.json").write_text(
                json.dumps(missing, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
            )
        out = L10N / f"_quality_fixes_{lang}.json"
        out.write_text(json.dumps(fixes, ensure_ascii=False, indent="\t") + "\n", encoding="utf-8")
        print(f"{lang}: {len(fixes)} fixes")


if __name__ == "__main__":
    main()
