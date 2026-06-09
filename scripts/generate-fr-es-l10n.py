#!/usr/bin/env python3
"""Generate l10n/fr.json and l10n/es.json from en.json with placeholder protection."""
import json
import re
import time
from pathlib import Path

from deep_translator import GoogleTranslator

ROOT = Path(__file__).resolve().parent.parent
L10N = ROOT / "l10n"
BATCH = 40

PLACEHOLDER_RE = re.compile(
    r"(%+\d*\$?[sdif]|%n|\{[a-zA-Z_]+\}|%%|BudgetCheck|Nextcloud|ISO 4217|IANA|SHA-256|EUR|USD|GBP|CHF|JPY|RUB|Europe/Berlin|Moscow|Excel|groupKey|CSRF|VAT)"
)


def protect(text: str) -> tuple[str, list[str]]:
    tokens: list[str] = []

    def repl(m: re.Match) -> str:
        tokens.append(m.group(0))
        return f"__PH{len(tokens) - 1}__"

    return PLACEHOLDER_RE.sub(repl, text), tokens


def restore(text: str, tokens: list[str]) -> str:
    for i, tok in enumerate(tokens):
        text = text.replace(f"__PH{i}__", tok)
    return text


def translate_batch(values: list[str], translator: GoogleTranslator) -> list[str]:
    protected_list: list[str] = []
    token_lists: list[list[str]] = []
    for text in values:
        protected, tokens = protect(text)
        protected_list.append(protected)
        token_lists.append(tokens)

    for attempt in range(4):
        try:
            translated = translator.translate_batch(protected_list)
            return [restore(t, tokens) for t, tokens in zip(translated, token_lists)]
        except Exception as exc:
            print(f"  batch retry {attempt + 1}: {exc}", flush=True)
            time.sleep(1.5 * (attempt + 1))

    # fallback one-by-one
    out = []
    for text, tokens in zip(values, token_lists):
        protected, _ = protect(text)
        for attempt in range(3):
            try:
                out.append(restore(translator.translate(protected), tokens))
                break
            except Exception:
                time.sleep(0.5)
        else:
            out.append(text)
        time.sleep(0.08)
    return out


def build_lang(en_data: dict, lang: str) -> dict:
    translator = GoogleTranslator(source="en", target=lang)
    keys = list(en_data["translations"].keys())
    values = [en_data["translations"][k] for k in keys]
    flat: list[tuple[str, int | None]] = []
    for ki, val in enumerate(values):
        if isinstance(val, list):
            for li, item in enumerate(val):
                flat.append((item, ki, li))
        else:
            flat.append((val, ki, None))

    translated_flat: list[str] = []
    texts = [t[0] for t in flat]
    total = len(texts)
    for start in range(0, total, BATCH):
        chunk = texts[start : start + BATCH]
        print(f"  [{lang}] {min(start + BATCH, total)}/{total}", flush=True)
        translated_flat.extend(translate_batch(chunk, translator))
        time.sleep(0.2)

    result_values: list = [None] * len(values)
    fi = 0
    for ki, val in enumerate(values):
        if isinstance(val, list):
            result_values[ki] = []
            for _ in val:
                result_values[ki].append(translated_flat[fi])
                fi += 1
        else:
            result_values[ki] = translated_flat[fi]
            fi += 1

    return dict(zip(keys, result_values))


GLOSSARY_FR = [
    (r"\bworkspace\b", "espace de travail"),
    (r"\bworkspaces\b", "espaces de travail"),
    (r"\bWorkspace\b", "Espace de travail"),
    (r"\bWorkspaces\b", "Espaces de travail"),
    (r"\bbooking\b", "écriture"),
    (r"\bbookings\b", "écritures"),
    (r"\bBooking\b", "Écriture"),
    (r"\bBookings\b", "Écritures"),
    (r"\bledger\b", "journal"),
    (r"\bLedger\b", "Journal"),
    (r"\btransaction\b", "écriture"),
    (r"\btransactions\b", "écritures"),
    (r"\bTransaction\b", "Écriture"),
    (r"\bTransactions\b", "Écritures"),
    (r"\bhousehold\b", "foyer"),
    (r"\bHousehold\b", "Foyer"),
    (r"\bsavings target\b", "objectif d'épargne"),
    (r"\bSavings target\b", "Objectif d'épargne"),
    (r"\bmonthly close\b", "clôture mensuelle"),
    (r"\bMonthly close\b", "Clôture mensuelle"),
    (r"\bsnapshot\b", "instantané"),
    (r"\bSnapshot\b", "Instantané"),
    (r"\bVAT\b", "TVA"),
    (r"\bgross\b", "brut"),
    (r"\bGross\b", "Brut"),
    (r"\bnet\b", "net"),
    (r"\bNet\b", "Net"),
    (r"\bmanager\b", "gestionnaire"),
    (r"\bManager\b", "Gestionnaire"),
    (r"\bcontributor\b", "contributeur"),
    (r"\bContributor\b", "Contributeur"),
    (r"\bviewer\b", "lecteur"),
    (r"\bViewer\b", "Lecteur"),
    (r"\buncategorized\b", "non catégorisé"),
    (r"\bUncategorized\b", "Non catégorisé"),
    (r"\bspreadsheet\b", "feuille de calcul"),
    (r"\bSpreadsheet\b", "Feuille de calcul"),
]

GLOSSARY_ES = [
    (r"\bworkspace\b", "espacio de trabajo"),
    (r"\bworkspaces\b", "espacios de trabajo"),
    (r"\bWorkspace\b", "Espacio de trabajo"),
    (r"\bWorkspaces\b", "Espacios de trabajo"),
    (r"\bbooking\b", "asiento"),
    (r"\bbookings\b", "asientos"),
    (r"\bBooking\b", "Asiento"),
    (r"\bBookings\b", "Asientos"),
    (r"\bledger\b", "libro contable"),
    (r"\bLedger\b", "Libro contable"),
    (r"\btransaction\b", "movimiento"),
    (r"\btransactions\b", "movimientos"),
    (r"\bTransaction\b", "Movimiento"),
    (r"\bTransactions\b", "Movimientos"),
    (r"\bhousehold\b", "hogar"),
    (r"\bHousehold\b", "Hogar"),
    (r"\bsavings target\b", "objetivo de ahorro"),
    (r"\bSavings target\b", "Objetivo de ahorro"),
    (r"\bmonthly close\b", "cierre mensual"),
    (r"\bMonthly close\b", "Cierre mensual"),
    (r"\bsnapshot\b", "instantánea"),
    (r"\bSnapshot\b", "Instantánea"),
    (r"\bVAT\b", "IVA"),
    (r"\bgross\b", "bruto"),
    (r"\bGross\b", "Bruto"),
    (r"\bnet\b", "neto"),
    (r"\bNet\b", "Neto"),
    (r"\bmanager\b", "gestor"),
    (r"\bManager\b", "Gestor"),
    (r"\bcontributor\b", "colaborador"),
    (r"\bContributor\b", "Colaborador"),
    (r"\bviewer\b", "lector"),
    (r"\bViewer\b", "Lector"),
    (r"\buncategorized\b", "sin categoría"),
    (r"\bUncategorized\b", "Sin categoría"),
    (r"\bspreadsheet\b", "hoja de cálculo"),
    (r"\bSpreadsheet\b", "Hoja de cálculo"),
]

MANUAL_FR = {
    "Budget saldo": "Solde budgétaire",
    "Budget saldo: {saldo}": "Solde budgétaire : {saldo}",
    "Cap": "Plafond",
    "Cap warning": "Alerte de plafond",
    "Cap usage": "Utilisation du plafond",
    "Cap: {cap}": "Plafond : {cap}",
    "Headroom: {headroom}": "Marge : {headroom}",
    "Quick access": "Accès rapide",
    "Add to quick access": "Ajouter à l'accès rapide",
    "Remove from quick access": "Retirer de l'accès rapide",
    "Quick access updated.": "Accès rapide mis à jour.",
    "Only quick access workspaces": "Uniquement les espaces de travail en accès rapide",
    "No quick access workspaces yet. Use Manage to pin your favorites.": "Aucun espace de travail en accès rapide. Utilisez « Gérer » pour épingler vos favoris.",
    "No quick access workspaces yet.": "Aucun espace de travail en accès rapide.",
    "Are you sure?": "Confirmer ?",
    "Dismiss": "Fermer",
    "Loading…": "Chargement…",
    "Loading summary…": "Chargement du résumé…",
    "Back to Nextcloud": "Retour à Nextcloud",
    "Skip to main content": "Aller au contenu principal",
    "Breadcrumb": "Fil d'Ariane",
    "Pagination": "Pagination",
    "Glossary": "Glossaire",
    "Help and glossary": "Aide et glossaire",
    "Words we use": "Termes utilisés",
    "Dashboard": "Tableau de bord",
    "Year-at-a-glance": "L'année en un coup d'œil",
    "After income − expenses − savings target": "Après revenus − dépenses − objectif d'épargne",
    "In: {income} · Out: {expense}": "Entrées : {income} · Sorties : {expense}",
    "Preview: Net {net} · VAT {vat} · Gross {gross}": "Aperçu : Net {net} · TVA {vat} · Brut {gross}",
    "Net {net} · VAT {vat} · Gross {gross}": "Net {net} · TVA {vat} · Brut {gross}",
    "Budget basis: {basis}": "Base budgétaire : {basis}",
    "VAT {rate}%": "TVA {rate} %",
    "0 % (none)": "0 % (aucune)",
    "Custom…": "Personnalisé…",
    "Title or notes…": "Titre ou notes…",
    "Search title or notes…": "Rechercher un titre ou des notes…",
    "Type at least two characters…": "Saisissez au moins deux caractères…",
    "New group…": "Nouveau groupe…",
    "%n uncategorized expense remains without a category. It counts toward the total but not toward any budget.": "%n dépense non catégorisée reste sans catégorie. Elle compte dans le total, mais pas dans un budget.",
    "%n uncategorized expenses remain without a category. They count toward the total but not toward any budget.": "%n dépenses non catégorisées restent sans catégorie. Elles comptent dans le total, mais pas dans un budget.",
    "%n workspace visible": "%n espace de travail visible",
    "%n workspaces visible": "%n espaces de travail visibles",
}

MANUAL_ES = {
    "Budget saldo": "Saldo presupuestario",
    "Budget saldo: {saldo}": "Saldo presupuestario: {saldo}",
    "Cap": "Límite",
    "Cap warning": "Aviso de límite",
    "Cap usage": "Uso del límite",
    "Cap: {cap}": "Límite: {cap}",
    "Headroom: {headroom}": "Margen: {headroom}",
    "Quick access": "Acceso rápido",
    "Add to quick access": "Añadir al acceso rápido",
    "Remove from quick access": "Quitar del acceso rápido",
    "Quick access updated.": "Acceso rápido actualizado.",
    "Only quick access workspaces": "Solo espacios de trabajo de acceso rápido",
    "No quick access workspaces yet. Use Manage to pin your favorites.": "Aún no hay espacios de trabajo en acceso rápido. Use «Administrar» para fijar sus favoritos.",
    "No quick access workspaces yet.": "Aún no hay espacios de trabajo en acceso rápido.",
    "Are you sure?": "¿Está seguro?",
    "Dismiss": "Cerrar",
    "Loading…": "Cargando…",
    "Loading summary…": "Cargando resumen…",
    "Back to Nextcloud": "Volver a Nextcloud",
    "Skip to main content": "Ir al contenido principal",
    "Breadcrumb": "Ruta de navegación",
    "Pagination": "Paginación",
    "Glossary": "Glosario",
    "Help and glossary": "Ayuda y glosario",
    "Words we use": "Términos que usamos",
    "Dashboard": "Panel",
    "Year-at-a-glance": "El año de un vistazo",
    "After income − expenses − savings target": "Tras ingresos − gastos − objetivo de ahorro",
    "In: {income} · Out: {expense}": "Entradas: {income} · Salidas: {expense}",
    "Preview: Net {net} · VAT {vat} · Gross {gross}": "Vista previa: Neto {net} · IVA {vat} · Bruto {gross}",
    "Net {net} · VAT {vat} · Gross {gross}": "Neto {net} · IVA {vat} · Bruto {gross}",
    "Budget basis: {basis}": "Base presupuestaria: {basis}",
    "VAT {rate}%": "IVA {rate} %",
    "0 % (none)": "0 % (ninguno)",
    "Custom…": "Personalizado…",
    "Title or notes…": "Título o notas…",
    "Search title or notes…": "Buscar título o notas…",
    "Type at least two characters…": "Escriba al menos dos caracteres…",
    "New group…": "Nuevo grupo…",
    "%n uncategorized expense remains without a category. It counts toward the total but not toward any budget.": "%n gasto sin categoría permanece sin categoría. Cuenta para el total, pero no para ningún presupuesto.",
    "%n uncategorized expenses remain without a category. They count toward the total but not toward any budget.": "%n gastos sin categoría permanecen sin categoría. Cuentan para el total, pero no para ningún presupuesto.",
    "%n workspace visible": "%n espacio de trabajo visible",
    "%n workspaces visible": "%n espacios de trabajo visibles",
}


def apply_glossary(text: str, glossary: list[tuple[str, str]]) -> str:
    for pattern, repl in glossary:
        text = re.sub(pattern, repl, text)
    return text


def polish(translations: dict, glossary, manual: dict) -> dict:
    out = {}
    for key, val in translations.items():
        if key in manual:
            out[key] = manual[key]
        elif isinstance(val, list):
            out[key] = [apply_glossary(v, glossary) for v in val]
        else:
            out[key] = apply_glossary(val, glossary)
    return out


def verify_placeholders(en_data: dict, lang_data: dict, lang: str) -> list[str]:
    issues = []
    ph_re = re.compile(r"%+\d*\$?[sdif]|%n|\{[a-zA-Z_]+\}|%%")
    for key, en_val in en_data["translations"].items():
        lang_val = lang_data["translations"].get(key)
        if lang_val is None:
            issues.append(f"missing key: {key}")
            continue
        en_phs = ph_re.findall(en_val if isinstance(en_val, str) else " ".join(en_val))
        lang_phs = ph_re.findall(lang_val if isinstance(lang_val, str) else " ".join(lang_val))
        if en_phs != lang_phs:
            issues.append(f"[{lang}] placeholder mismatch for {key!r}: {en_phs} vs {lang_phs}")
        if isinstance(lang_val, str) and lang_val == en_val and not re.match(r"^[\d\s%\.]+$", en_val):
            # allow unchanged product names / codes
            if "BudgetCheck" not in en_val and "Nextcloud" not in en_val and "ISO" not in en_val:
                if len(en_val) > 3 and not en_val.isupper():
                    issues.append(f"[{lang}] untranslated: {key!r}")
    return issues


def main() -> None:
    en_data = json.loads((L10N / "en.json").read_text(encoding="utf-8"))

    print("Translating to French …")
    fr_trans = build_lang(en_data, "fr")
    fr_trans = polish(fr_trans, GLOSSARY_FR, MANUAL_FR)

    print("Translating to Spanish …")
    es_trans = build_lang(en_data, "es")
    es_trans = polish(es_trans, GLOSSARY_ES, MANUAL_ES)

    fr_out = {"translations": fr_trans, "pluralForm": "nplurals=2; plural=(n > 1);"}
    es_out = {"translations": es_trans, "pluralForm": "nplurals=2; plural=(n != 1);"}

    (L10N / "fr.json").write_text(json.dumps(fr_out, ensure_ascii=False, indent="\t") + "\n", encoding="utf-8")
    (L10N / "es.json").write_text(json.dumps(es_out, ensure_ascii=False, indent="\t") + "\n", encoding="utf-8")

    fr_issues = verify_placeholders(en_data, fr_out, "fr")
    es_issues = verify_placeholders(en_data, es_out, "es")
    print(f"Wrote fr.json and es.json ({len(fr_trans)} keys each)")
    if fr_issues:
        print(f"FR issues ({len(fr_issues)}):")
        for item in fr_issues[:20]:
            print(" ", item)
    if es_issues:
        print(f"ES issues ({len(es_issues)}):")
        for item in es_issues[:20]:
            print(" ", item)


if __name__ == "__main__":
    main()
