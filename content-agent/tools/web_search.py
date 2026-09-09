"""
Web-Tools für den Research-Agenten.

Web-Suche über EdenAI Universal-AI mit Firecrawl (web/search + web/scraping) —
benötigt nur den EdenAI-Key. (SerperDev-Fallback entfernt — EdenAI ersetzt ihn.)
"""
import httpx
from haystack.tools import tool

from config import EDENAI_API_KEY

EDENAI_BASE = "https://api.edenai.run/v3/universal-ai/"


def _edenai_search(query: str, limit: int) -> dict | None:
    """EdenAI/Firecrawl-Suche. Returns None, wenn EdenAI nicht verfügbar."""
    if not EDENAI_API_KEY:
        return None
    r = httpx.post(
        EDENAI_BASE,
        headers={"Authorization": f"Bearer {EDENAI_API_KEY}", "Content-Type": "application/json"},
        json={
            "model": "web/search/firecrawl",
            "input": {"query": query, "depth": "standard"},
            "show_original_response": False,
        },
        timeout=90,
    )
    data = r.json()
    if r.status_code != 200 or data.get("status") != "success":
        return {"results": [], "error": str(data.get("error") or f"HTTP {r.status_code}")}

    results = []
    for item in (data.get("output") or {}).get("results", [])[:limit]:
        results.append({
            "title": item.get("title"),
            "url": item.get("url"),
            "description": item.get("content"),
        })
    return {"results": results}


@tool
def web_search(query: str, limit: int = 5) -> dict:
    """Durchsuche das Web nach aktuellen Informationen, Artikeln, Studien und News
    zu einem Thema. Liefert Titel, URL und eine Kurzbeschreibung pro Treffer.

    :param query: Die Suchanfrage (z. B. "CRM Datenqualität B2B SaaS")
    :param limit: Maximale Anzahl Treffer (1-10)
    """
    limit = max(1, min(10, int(limit)))
    edenai = _edenai_search(query, limit)
    if edenai is not None:
        return edenai
    return {"results": [], "error": "Kein Web-Search konfiguriert (EdenAI-Key fehlt)."}


@tool
def scrape_page(url: str) -> dict:
    """Lade den vollständigen Inhalt einer URL (Markdown) — z. B. einen Artikel
    aus einem Web-Search-Treffer genau lesen, um Angles zu extrahieren.

    :param url: Die zu lesende URL (inkl. https://)
    """
    if not EDENAI_API_KEY:
        return {"error": "EdenAI-Key fehlt — Scraping nicht möglich."}

    r = httpx.post(
        EDENAI_BASE,
        headers={"Authorization": f"Bearer {EDENAI_API_KEY}", "Content-Type": "application/json"},
        json={
            "model": "web/scraping/firecrawl",
            "input": {"url": url},
            "show_original_response": False,
        },
        timeout=120,
    )
    data = r.json()
    if r.status_code != 200 or data.get("status") != "success":
        return {"error": str(data.get("error") or f"HTTP {r.status_code}"), "url": url}

    content = (data.get("output") or {}).get("content") or ""
    # Für den Agenten-Prompt kürzen, sonst sprengt es den Kontext
    max_len = 8000
    truncated = len(content) > max_len
    return {
        "url": url,
        "content": content[:max_len],
        "truncated": truncated,
    }
