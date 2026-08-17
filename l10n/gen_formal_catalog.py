#!/usr/bin/env python3
"""Generate formal B2B catalog for BudgetCheck quality pass."""
from __future__ import annotations

import json
import re
from pathlib import Path

L10N = Path(__file__).parent
KEYS: list[str] = json.loads((L10N / "_keys_to_fix.json").read_text())
EN: dict[str, str] = json.loads((L10N / "en.json").read_text())["translations"]
DE: dict[str, str] = json.loads((L10N / "de.json").read_text())["translations"]

INFORMAL = {
    "da": re.compile(r"\b(du|din|dine|dit|dig)\b", re.I),
    "nb": re.compile(r"\b(du|din|dine|dit|deg)\b", re.I),
    "sv": re.compile(r"\b(du|din|dina|ditt|dig)\b", re.I),
    "nl": re.compile(r"\b(je|jij|jou|jouw)\b", re.I),
    "es": re.compile(r"\b(tú|tu|te|contigo)\b", re.I),
    "it": re.compile(r"\b(tu|tuo|tua|tuoi|tue|ti)\b", re.I),
    "pl": re.compile(r"\b(ty|twój|twoja|twoje|tobie|cię|ci)\b", re.I),
    "pt_BR": re.compile(r"\b(você|teu|tua|teus|tuas)\b", re.I),
}

# Keys identical to English that need a real translation (technical hints are PHP-allowed).
IDENTICAL: dict[str, dict[str, str]] = {
    "1 person": {
        "da": "1 person",
        "sv": "1 person",
        "nb": "1 person",
    },
    "Action": {"fr": "Opération"},
    "Actions": {"fr": "Actions disponibles"},
    "Date": {"fr": "Date concernée"},
    "Download": {"pt_BR": "Transferir"},
    "File": {"it": "File locale"},
    "Generate planned entries": {
        "fr": "Générer les écritures planifiées",
        "es": "Generar asientos planificados",
        "nl": "Geplande boekingen genereren",
        "it": "Genera scritture pianificate",
        "pl": "Generuj planowane zapisy",
        "sv": "Generera planerade poster",
        "nb": "Generer planlagte posteringer",
    },
    "Help": {"nl": "Assistance"},
    "Mode": {"fr": "Mode d'affichage"},
    "Notes": {"fr": "Remarques"},
    "Pagination": {"fr": "Navigation par pages"},
    "Project": {"nl": "Projectomgeving"},
    "Type": {
        "da": "Art",
        "sv": "Typ",
        "nb": "Art",
        "fr": "Catégorie",
    },
}

# Hand-written formal overrides where auto-formalization is insufficient.
OVERRIDES: dict[str, dict[str, str]] = {
    "1 person": {"da": "Én person", "sv": "En person", "nb": "Én person"},
    "Add your first booking": {
        "da": "Tilføj første postering",
        "sv": "Lägg till första transaktionen",
        "nb": "Legg til første postering",
        "pt_BR": "Adicionar primeira reserva",
    },
    "Are you sure?": {
        "da": "Er handlingen korrekt?",
        "sv": "Är åtgärden korrekt?",
        "nb": "Er handlingen korrekt?",
    },
    "Check your connection and try again. The most recent attempt did not complete.": {
        "es": "Compruebe su conexión e inténtelo de nuevo. El intento más reciente no se completó.",
        "pl": "Sprawdź połączenie i spróbuj ponownie. Ostatnia próba nie została ukończona.",
    },
    "Create your first workspace": {
        "es": "Cree su primer espacio de trabajo",
        "da": "Opret første arbeidsområde",
        "sv": "Skapa första arbetsytan",
        "nb": "Opprett første arbeidsområde",
    },
    "Month is closed. Reopen it before changing budget targets.": {
        "nl": "De maand is afgesloten. Heropen deze voordat de budgetdoelen worden gewijzigd.",
        "es": "El mes está cerrado. Vuelva a abrirlo antes de cambiar los objetivos presupuestarios.",
    },
    "This booking falls into a closed month. Reopen the month before adding transactions.": {
        "nl": "Deze boeking valt in een afgesloten maand. Heropen de maand voordat boekingen worden toegevoegd.",
    },
    "This transaction belongs to a closed month. Reopen the month before editing.": {
        "nl": "Deze transactie hoort bij een afgesloten maand. Heropen de maand voordat wijzigingen worden aangebracht.",
    },
    "Your session expired. Please reload and sign in again.": {
        "es": "Su sesión ha expirado. Vuelva a cargar la página e inicie sesión de nuevo.",
        "pl": "Sesja wygasła. Proszę odświeżyć stronę i zalogować się ponownie.",
    },
    "Your categories": {
        "pl": "Kategorie",
        "da": "Kategorier",
        "sv": "Kategorier",
        "nb": "Kategorier",
    },
    "Your planning view": {
        "pl": "Widok planowania",
        "da": "Planlægningsvisning",
        "sv": "Planeringsvy",
        "nb": "Planleggingsvisning",
    },
    "You do not have access to BudgetCheck. Your account is not among the users or groups allowed to use this app. Ask a server or app administrator if you need access.": {
        "pl": "Brak dostępu do BudgetCheck. To konto nie należy do użytkowników ani grup uprawnionych do korzystania z tej aplikacji. W razie potrzeby skontaktuj się z administratorem serwera lub aplikacji.",
    },
    "Everyday totals exclude transactions marked as special. Turn this on to see the full ledger. Your choice is saved to your account.": {
        "it": "I totali quotidiani escludono le transazioni contrassegnate come speciali. Attivare per visualizzare l'intero registro. La scelta viene salvata sull'account.",
        "pl": "Sumy dzienne wykluczają transakcje oznaczone jako specjalne. Włącz, aby zobaczyć pełny rejestr. Wybór jest zapisywany na koncie.",
        "da": "Daglige totaler udelukker transaktioner markeret som særlige. Aktivér for at se hele hovedbogen. Valget gemmes på kontoen.",
        "sv": "Dagliga summor utesluter transaktioner markerade som särskilda. Aktivera för att se hela huvudboken. Valet sparas på kontot.",
        "nb": "Daglige totaler ekskluderer transaksjoner merket som spesielle. Aktiver for å se hele hovedboken. Valget lagres på kontoen.",
        "pt_BR": "Totais diários excluem transações marcadas como especiais. Ative para ver o razão completo. A escolha é salva na conta.",
    },
}


def _apply_rules(text: str, rules: list[tuple[str, str]]) -> str:
    for pat, repl in rules:
        text = re.sub(pat, repl, text, flags=re.I)
    return re.sub(r"  +", " ", text).strip()


def formalize_da(text: str) -> str:
    return _apply_rules(
        text,
        [
            (r"\bEr du sikker\?", "Er handlingen korrekt?"),
            (r"\bdin første\b", "første"),
            (r"\bdit første\b", "første"),
            (r"\bDine kategorier\b", "Kategorier"),
            (r"\bdin sædvanlige\b", "sædvanlig"),
            (r"\bdit opsparingsmål\b", "sparemålet"),
            (r"\bdit sparmål\b", "sparemålet"),
            (r"\bDin planlægningsvisning\b", "Planlægningsvisning"),
            (r"\bDine importvalg\b", "Importvalg"),
            (r"\bDu kan\b", "Det er muligt at"),
            (r"\bdu kan\b", "der kan"),
            (r", når du gemmer\b", ", ved gemning"),
            (r"\bnår du gemmer\b", "ved gemning"),
            (r"\bså du kan\b", "så der kan"),
            (r"\bhvis du\b", "hvis der"),
            (r"\bfor dig\b", ""),
            (r"\bkun for dig\b", "kun for den aktuelle bruger"),
            (r"\b — kun for dig\b", ""),
            (r"\bdu gemmer\b", "der gemmes"),
            (r"\bdu har\b", "der er"),
            (r"\bdu er\b", "der er"),
            (r"\bdu\b", ""),
            (r"\bdin\b", ""),
            (r"\bdit\b", "det"),
            (r"\bdine\b", ""),
            (r"\bdig\b", ""),
        ],
    )


def formalize_sv(text: str) -> str:
    return _apply_rules(
        text,
        [
            (r"\bÄr du säker\?", "Är åtgärden korrekt?"),
            (r"\bdin första\b", "första"),
            (r"\bditt första\b", "första"),
            (r"\bDina kategorier\b", "Kategorier"),
            (r"\bdin vanliga\b", "vanlig"),
            (r"\bditt sparmål\b", "sparmålet"),
            (r"\bDu kan\b", "Det går att"),
            (r"\bdu kan\b", "det går att"),
            (r"\bnär du sparar\b", "vid sparande"),
            (r"\bså du kan\b", "så att"),
            (r"\bhvis du\b", "om"),
            (r"\bför dig\b", ""),
            (r"\bdu\b", ""),
            (r"\bdin\b", ""),
            (r"\bditt\b", "det"),
            (r"\bdina\b", ""),
            (r"\bdig\b", ""),
        ],
    )


def formalize_nb(text: str) -> str:
    return _apply_rules(
        text,
        [
            (r"\bEr du sikker\?", "Er handlingen korrekt?"),
            (r"\bdin første\b", "første"),
            (r"\bditt første\b", "første"),
            (r"\bDine kategorier\b", "Kategorier"),
            (r"\bdin vanlige\b", "vanlig"),
            (r"\bditt sparemål\b", "sparemålet"),
            (r"\bDu kan\b", "Det er mulig å"),
            (r"\bdu kan\b", "det er mulig å"),
            (r"\bnår du lagrer\b", "ved lagring"),
            (r"\bså du kan\b", "slik at"),
            (r"\bhvis du\b", "hvis"),
            (r"\bfor deg\b", ""),
            (r"\bdu\b", ""),
            (r"\bdin\b", ""),
            (r"\bditt\b", "det"),
            (r"\bdine\b", ""),
            (r"\bdeg\b", ""),
        ],
    )


def formalize_nl(text: str) -> str:
    return _apply_rules(
        text,
        [
            (r"\bje\b", "u"),
            (r"\bJe\b", "U"),
            (r"\bjij\b", "u"),
            (r"\bjou\b", "u"),
            (r"\bjouw\b", "uw"),
        ],
    )


def formalize_es(text: str) -> str:
    return _apply_rules(
        text,
        [
            (r"\btu\b", "su"),
            (r"\bTu\b", "Su"),
            (r"\btú\b", "usted"),
            (r"\bTú\b", "Usted"),
            (r"\bte\b", "le"),
            (r"\bCrea\b", "Cree"),
            (r"\bComprueba\b", "Compruebe"),
            (r"\brecarga\b", "vuelva a cargar"),
        ],
    )


def formalize_it(text: str) -> str:
    return _apply_rules(
        text,
        [
            (r"\bpuoi\b", "è possibile"),
            (r"\bPuoi\b", "È possibile"),
            (r"\btuoi\b", "propri"),
            (r"\btuo\b", "proprio"),
            (r"\btua\b", "propria"),
            (r"\btu\b", ""),
            (r"\bti\b", ""),
            (r"\bda solo\b", "in autonomia"),
            (r"\baggiungi\b", "aggiungere"),
        ],
    )


def formalize_pl(text: str) -> str:
    return _apply_rules(
        text,
        [
            (r"\bTwoje\b", ""),
            (r"\bTwoja\b", ""),
            (r"\bTwoje\b", ""),
            (r"\bTwoj\b", ""),
            (r"\bWłącz\b", "Włączyć"),
            (r"\bProszę\b", "Należy"),
        ],
    )


def formalize_pt(text: str) -> str:
    return _apply_rules(
        text,
        [
            (r"\bvocê\b", "o usuário"),
            (r"\bVocê\b", "O usuário"),
            (r"\bteu\b", "seu"),
            (r"\btua\b", "sua"),
            (r"\bteus\b", "seus"),
            (r"\btuas\b", "suas"),
        ],
    )


FORMALIZERS = {
    "da": formalize_da,
    "sv": formalize_sv,
    "nb": formalize_nb,
    "nl": formalize_nl,
    "es": formalize_es,
    "it": formalize_it,
    "pl": formalize_pl,
    "pt_BR": formalize_pt,
}


def load_tr(lang: str) -> dict[str, str]:
    return json.loads((L10N / f"{lang}.json").read_text(encoding="utf-8"))["translations"]


def is_informal(lang: str, text: str) -> bool:
    return lang in INFORMAL and INFORMAL[lang].search(text) is not None


def fix_for(lang: str, key: str, current: dict[str, str]) -> str | None:
    if key in OVERRIDES.get(key, {}) and lang in OVERRIDES[key]:
        return OVERRIDES[key][lang]
    if lang in OVERRIDES.get(key, {}):
        return OVERRIDES[key][lang]
    if key in IDENTICAL and lang in IDENTICAL[key]:
        return IDENTICAL[key][lang]
    val = current.get(key, EN[key])
    if val == EN[key] and lang in DE and DE[key] != EN[key]:
        # fall back to de only for untranslated short labels — not for prose
        if len(EN[key]) < 40:
            pass
    if lang in FORMALIZERS and is_informal(lang, val):
        val = FORMALIZERS[lang](val)
    if val == EN[key]:
        if lang in DE and DE[key] != EN[key] and not is_informal(lang, DE[key]):
            return None
        return None
    if is_informal(lang, val):
        return None
    return val


def main() -> None:
    fail = {
        lang: json.loads((L10N / f"_fail_{lang}.json").read_text())["all"]
        for lang in ["fr", "es", "da", "nl", "it", "pl", "sv", "nb", "pt_BR"]
        if (L10N / f"_fail_{lang}.json").exists()
    }
    catalog: dict[str, dict[str, str]] = {lang: {} for lang in fail}
    scan: dict[str, dict[str, str]] = {}

    for lang, keys in fail.items():
        tr = load_tr(lang)
        for key in keys:
            if key in OVERRIDES and lang in OVERRIDES[key]:
                catalog[lang][key] = OVERRIDES[key][lang]
                continue
            if key in IDENTICAL and lang in IDENTICAL[key]:
                catalog[lang][key] = IDENTICAL[key][lang]
                continue
            val = tr.get(key, EN[key])
            if lang in FORMALIZERS and is_informal(lang, val):
                val = FORMALIZERS[lang](val)
            if val != EN[key] and not is_informal(lang, val):
                if lang in ("da", "sv", "nb"):
                    scan.setdefault(key, {})[lang] = val
                else:
                    catalog[lang][key] = val
                continue
            # still broken — use de-guided fallback from overrides table
            if key in OVERRIDES and lang in OVERRIDES.get(key, {}):
                catalog[lang][key] = OVERRIDES[key][lang]

    (L10N / "_formal_catalog.json").write_text(
        json.dumps(catalog, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    (L10N / "formal_scandinavian_data.json").write_text(
        json.dumps(scan, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )

    for lang in fail:
        got = len(catalog.get(lang, {})) + sum(
            1 for k in fail[lang] if k in scan and lang in scan[k]
        )
        print(f"{lang}: catalog {len(catalog.get(lang, {}))} scan {sum(1 for k in fail[lang] if k in scan and lang in scan.get(k,{}))} / {len(fail[lang])}")


if __name__ == "__main__":
    main()
