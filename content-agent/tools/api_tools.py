"""
API-Tools für den Contentor-Agenten.
Wrappen alle Laravel-API-Endpunkte als Haystack-Tools.
"""
import httpx
from haystack.tools import tool
from config import CONTENT_API_URL, CONTENT_STRATEGY


# ─── Strategie-Kontext ────────────────────────────────────

@tool
def get_strategy_context(persona: str | None = None) -> dict:
    """Lade den vollständigen Kampagnen-Kontext (ICPs, Pain-Cluster,
    Tonalität, Brand Voice, Personas) aus der Contentor-API.

    Rufe dieses Tool ZUERST auf, bevor du recherchierst oder Angles erstellst,
    damit alle Ergebnisse zur Strategie und zur Ziel-Persona passen.

    :param persona: Name einer bestimmten Ziel-Persona (optional).
        Wenn gesetzt, enthält der Kontext nur diese Persona.
    """
    # Persona-Name → ID auflösen (Python-Seite kennt nur Namen aus dem Chat)
    persona_id = None
    if persona:
        r = httpx.get(f"{CONTENT_API_URL}/personas", params={"active": 1})
        r.raise_for_status()
        for p in r.json().get("data", r.json() if isinstance(r.json(), list) else []):
            if persona.lower() in (p.get("name") or "").lower():
                persona_id = p["id"]
                break

    params = {"strategy": CONTENT_STRATEGY, "agent": "research"}
    if persona_id:
        params["persona_id"] = persona_id
    r = httpx.get(f"{CONTENT_API_URL}/agents/context", params=params)
    r.raise_for_status()
    return r.json()


# ─── Quellen ───────────────────────────────────────────────

@tool
def create_source(
    title: str,
    type: str,
    strategy: str = CONTENT_STRATEGY,
    visibility: str = "intern",
    file_ref: str | None = None,
    batch_key: str | None = None,
    url: str | None = None,
) -> dict:
    """Lege eine neue Recherche-Quelle an.

    :param title: Titel der Quelle (z. B. "HubSpot Pricing 2025")
    :param type: Quelltyp: pdf, url, interview, intern, research
    :param strategy: Unit-Key (z. B. "viscale")
    :param visibility: Sichtbarkeit: intern, extern, partner
    :param file_ref: Dateireferenz (optional)
    :param batch_key: Batch-Key zur Gruppierung (optional)
    :param url: URL der Quelle — bei Typ "url" IMMER angeben,
        damit die Quelle später überwacht/gecrawlt werden kann
    """
    r = httpx.post(f"{CONTENT_API_URL}/sources", json={
        "title": title, "type": type, "strategy": strategy,
        "visibility": visibility, "file_ref": file_ref, "batch_key": batch_key,
        "url": url,
    })
    r.raise_for_status()
    return r.json()


@tool
def list_sources(
    strategy: str = CONTENT_STRATEGY,
    type: str | None = None,
    batch_key: str | None = None,
    per_page: int = 50,
) -> dict:
    """Liste alle Quellen einer Unit.

    :param strategy: Unit-Key
    :param type: Nach Quelltyp filtern (pdf, url, etc.)
    :param batch_key: Nach Batch filtern
    :param per_page: Ergebnisse pro Seite
    """
    params = {"strategy": strategy, "per_page": per_page}
    if type: params["type"] = type
    if batch_key: params["batch_key"] = batch_key
    r = httpx.get(f"{CONTENT_API_URL}/sources", params=params)
    r.raise_for_status()
    return r.json()


@tool
def get_source_angles(source_id: str) -> dict:
    """Zeige alle Angles einer Quelle.

    :param source_id: Source-ID (z. B. SRC-000001)
    """
    r = httpx.get(f"{CONTENT_API_URL}/sources/{source_id}/angles")
    r.raise_for_status()
    return r.json()


# ─── Angles ────────────────────────────────────────────────

@tool
def create_angle(
    angle: str,
    strategy: str = CONTENT_STRATEGY,
    icp: str | None = None,
    pain_cluster: str | None = None,
    statement_type: str | None = None,
    source_id: str | None = None,
    batch_key: str | None = None,
    funnel: str | None = None,
    viscale_phase: str | None = None,
) -> dict:
    """Lege einen neuen Content-Angle an.

    ICP, Pain-Cluster und Statement-Type werden automatisch erkannt,
    wenn sie nicht explizit angegeben werden.

    :param angle: Der Angle-Text (z. B. Kernaussage)
    :param strategy: Unit-Key
    :param icp: ICP-Zuordnung (optional, wird auto-detektiert)
    :param pain_cluster: Pain-Cluster (optional)
    :param statement_type: Statement-Typ (optional)
    :param source_id: Zugehörige Source-ID (optional)
    :param batch_key: Batch-Key zur Gruppierung (optional)
    :param funnel: ToFu, MoFu, BoFu (optional)
    :param viscale_phase: Viscale-Phase (optional)
    """
    payload = {"angle": angle, "strategy": strategy}
    for k, v in [("icp", icp), ("pain_cluster", pain_cluster),
                  ("statement_type", statement_type), ("source_id", source_id),
                  ("batch_key", batch_key), ("funnel", funnel),
                  ("viscale_phase", viscale_phase)]:
        if v is not None: payload[k] = v
    r = httpx.post(f"{CONTENT_API_URL}/angles", json=payload)
    r.raise_for_status()
    return r.json()


@tool
def list_angles(
    strategy: str = CONTENT_STRATEGY,
    batch: str | None = None,
    icp: str | None = None,
    status: str | None = None,
    funnel: str | None = None,
    min_score: int | None = None,
    sort: str = "ranking_score",
    direction: str = "desc",
    per_page: int = 50,
) -> dict:
    """Liste alle Angles mit optionalen Filtern.

    :param strategy: Unit-Key
    :param batch: Nach Batch filtern
    :param icp: Nach ICP filtern
    :param status: Nach Status filtern
    :param funnel: Nach Funnel-Stufe filtern (ToFu, MoFu, BoFu)
    :param min_score: Mindest-Ranking-Score
    :param sort: Sortierfeld (default: ranking_score)
    :param direction: Sortierrichtung (asc/desc)
    :param per_page: Ergebnisse pro Seite
    """
    params = {"strategy": strategy, "sort": sort, "dir": direction, "per_page": per_page}
    if batch: params["batch"] = batch
    if icp: params["icp"] = icp
    if status: params["status"] = status
    if funnel: params["funnel"] = funnel
    if min_score is not None: params["min_score"] = min_score
    r = httpx.get(f"{CONTENT_API_URL}/angles", params=params)
    r.raise_for_status()
    return r.json()


@tool
def update_angle(
    angle_id: str,
    angle: str | None = None,
    icp: str | None = None,
    pain_cluster: str | None = None,
    statement_type: str | None = None,
    funnel: str | None = None,
    viscale_phase: str | None = None,
    status: str | None = None,
    r_zielgruppe: int | None = None,
    r_viscale_fit: int | None = None,
    r_schaerfe: int | None = None,
    r_timing: int | None = None,
    score_reasoning: str | None = None,
) -> dict:
    """Aktualisiere einen Angle (inkl. Ranking-Kriterien).

    :param angle_id: Angle-ID (z. B. ANG-000001)
    :param angle: Neuer Angle-Text (optional)
    :param icp: ICP (optional)
    :param pain_cluster: Pain-Cluster (optional)
    :param statement_type: Statement-Typ (optional)
    :param funnel: ToFu, MoFu, BoFu (optional)
    :param viscale_phase: Viscale-Phase (optional)
    :param status: Status (optional)
    :param r_zielgruppe: Ranking: Zielgruppe 1-3
    :param r_viscale_fit: Ranking: Viscale-Fit 1-3
    :param r_schaerfe: Ranking: Schärfe 1-3
    :param r_timing: Ranking: Timing 1-3
    :param score_reasoning: Kurze Begründung der Bewertung (1-2 Sätze)
    """
    payload = {}
    for k, v in [("angle", angle), ("icp", icp), ("pain_cluster", pain_cluster),
                  ("statement_type", statement_type), ("funnel", funnel),
                  ("viscale_phase", viscale_phase), ("status", status),
                  ("r_zielgruppe", r_zielgruppe), ("r_viscale_fit", r_viscale_fit),
                  ("r_schaerfe", r_schaerfe), ("r_timing", r_timing),
                  ("score_reasoning", score_reasoning)]:
        if v is not None: payload[k] = v
    r = httpx.patch(f"{CONTENT_API_URL}/angles/{angle_id}", json=payload)
    r.raise_for_status()
    return r.json()


@tool
def get_batch_ranking(batch_key: str, strategy: str = CONTENT_STRATEGY, min_score: int | None = None) -> dict:
    """Zeige das vollständige Batch-Ranking aller Angles eines Batches.

    :param batch_key: Der Batch-Key
    :param strategy: Unit-Key
    :param min_score: Mindest-Score-Filter (optional)
    """
    params = {"strategy": strategy}
    if min_score is not None: params["min_score"] = min_score
    r = httpx.get(f"{CONTENT_API_URL}/angles/batch/{batch_key}", params=params)
    r.raise_for_status()
    return r.json()


# ─── Content ───────────────────────────────────────────────

@tool
def create_content_idea(
    input_text: str,
    strategy: str = CONTENT_STRATEGY,
    icp: str | None = None,
    source_type: str | None = None,
) -> dict:
    """Lege eine neue Content-Idee an.

    :param input_text: Der Eingabetext der Idee
    :param strategy: Unit-Key
    :param icp: ICP (optional, wird auto-detektiert)
    :param source_type: Quelltyp (optional)
    """
    payload = {"input": input_text, "strategy": strategy}
    if icp: payload["icp"] = icp
    if source_type: payload["source_type"] = source_type
    r = httpx.post(f"{CONTENT_API_URL}/content/idee", json=payload)
    r.raise_for_status()
    return r.json()


@tool
def produce_content(
    angle_id: str,
    format: str,
    pattern: str | None = None,
    persona_id: str | None = None,
    metric: str | None = None,
    mechanism: str | None = None,
    proofs: str | None = None,
    kpis: str | None = None,
    cta: str | None = None,
    variants_count: int | None = None,
    variant_patterns: list[str] | None = None,
    strategy: str = CONTENT_STRATEGY,
) -> dict:
    """Produziere Content aus einem Angle. Das Backend generiert den Text
    anhand von Pattern-Template, Kanal-Regeln, Brand Voice und Persona.

    :param angle_id: Angle-ID (z. B. ANG-000001)
    :param format: linkedin_post, ad_copy, newsletter_acquisition, landing_page_headlines, newsletter_bk
    :param pattern: Inhaltliches Template (contrarian_take, data_drop, mistake_post, framework)
    :param persona_id: Ziel-Persona-ID (optional)
    :param metric: Metrik/Claim (optional)
    :param mechanism: Mechanismus-Beschreibung (optional)
    :param proofs: Beweise/Belege (optional)
    :param kpis: KPIs (optional)
    :param cta: Call-to-Action (optional)
    :param variants_count: Anzahl A/B-Varianten (1-5, default 1)
    :param variant_patterns: Welche Varianten (story, listicle, contrarian, question, data_drop)
    :param strategy: Unit-Key
    """
    payload = {"angle_id": angle_id, "format": format, "strategy": strategy}
    for k, v in [("pattern", pattern), ("persona_id", persona_id),
                  ("metric", metric), ("mechanism", mechanism),
                  ("proofs", proofs), ("kpis", kpis), ("cta", cta),
                  ("variants_count", variants_count), ("variant_patterns", variant_patterns)]:
        if v is not None: payload[k] = v
    r = httpx.post(f"{CONTENT_API_URL}/content/produzieren", json=payload)
    r.raise_for_status()
    return r.json()


@tool
def revise_content(
    content_id: str,
    feedback: str,
) -> dict:
    """Markiere ein Content-Item zur Ueberarbeitung auf Basis von Review-Feedback.
    Wird vom Coordinator genutzt, wenn der Review-Agent Maengel (verdict: fail) meldet.

    :param content_id: Content-Item-ID (z. B. CNT-000001)
    :param feedback: Konkrete Verbesserungsanweisungen aus dem Review
    """
    r = httpx.patch(f"{CONTENT_API_URL}/content/{content_id}", json={
        "status": "in_produktion",
    })
    r.raise_for_status()
    return {"revised": content_id, "feedback": feedback, "status": "in_produktion"}


@tool
def list_content(
    strategy: str = CONTENT_STRATEGY,
    type: str | None = None,
    status: str | None = None,
    icp: str | None = None,
    per_page: int = 50,
) -> dict:
    """Liste alle Content-Items.

    :param strategy: Unit-Key
    :param type: post, idea (optional)
    :param status: idee, angle, in_produktion, review, geplant, live, verworfen
    :param icp: Nach ICP filtern
    :param per_page: Ergebnisse pro Seite
    """
    params = {"strategy": strategy, "per_page": per_page}
    if type: params["type"] = type
    if status: params["status"] = status
    if icp: params["icp"] = icp
    r = httpx.get(f"{CONTENT_API_URL}/content", params=params)
    r.raise_for_status()
    return r.json()


@tool
def update_content(
    content_id: str,
    title: str | None = None,
    content: str | None = None,
    status: str | None = None,
    format: str | None = None,
    owner: str | None = None,
    live_date: str | None = None,
    icp: str | None = None,
    persona_id: str | None = None,
) -> dict:
    """Aktualisiere ein Content-Item.

    :param content_id: Content-Item-ID (z. B. CNT-000001)
    :param title: Titel
    :param content: Content-Text
    :param status: idee, angle, in_produktion, review, geplant, live, verworfen
    :param format: Format
    :param owner: Verantwortlicher
    :param live_date: Live-Datum
    :param icp: ICP
    :param persona_id: Persona-ID
    """
    payload = {}
    for k, v in [("title", title), ("content", content), ("status", status),
                  ("format", format), ("owner", owner), ("live_date", live_date),
                  ("icp", icp), ("persona_id", persona_id)]:
        if v is not None: payload[k] = v
    r = httpx.patch(f"{CONTENT_API_URL}/content/{content_id}", json=payload)
    r.raise_for_status()
    return r.json()


@tool
def get_overview(strategy: str = CONTENT_STRATEGY) -> dict:
    """Dashboard-Übersicht: Stats, Top-Angles, anstehende Plan-Einträge.

    :param strategy: Unit-Key
    """
    r = httpx.get(f"{CONTENT_API_URL}/content/overview", params={"strategy": strategy})
    r.raise_for_status()
    return r.json()


# ─── Redaktionsplan ────────────────────────────────────────

@tool
def list_redaktionsplan(
    strategy: str = CONTENT_STRATEGY,
    from_date: str | None = None,
    to_date: str | None = None,
    status: str | None = None,
    mode: str = "list",
) -> dict:
    """Redaktionsplan anzeigen (Liste oder Kanban).

    :param strategy: Unit-Key
    :param from_date: Von-Datum (YYYY-MM-DD)
    :param to_date: Bis-Datum (YYYY-MM-DD)
    :param status: geplant, in_arbeit, fertig, live
    :param mode: "list" oder "kanban"
    """
    params = {"strategy": strategy, "mode": mode}
    if from_date: params["from"] = from_date
    if to_date: params["to"] = to_date
    if status: params["status"] = status
    r = httpx.get(f"{CONTENT_API_URL}/redaktionsplan", params=params)
    r.raise_for_status()
    return r.json()


@tool
def create_plan_entry(
    content_item_id: str,
    planned_date: str,
    strategy: str = CONTENT_STRATEGY,
    channel: str | None = None,
    status: str = "geplant",
    notes: str | None = None,
) -> dict:
    """Redaktionsplan-Eintrag anlegen.

    :param content_item_id: Content-Item-ID
    :param planned_date: Geplantes Datum (YYYY-MM-DD)
    :param strategy: Unit-Key
    :param channel: Kanal (optional)
    :param status: geplant, in_arbeit, fertig, live
    :param notes: Notizen (optional)
    """
    payload = {
        "content_item_id": content_item_id,
        "planned_date": planned_date,
        "strategy": strategy,
        "status": status,
    }
    if channel: payload["channel"] = channel
    if notes: payload["notes"] = notes
    r = httpx.post(f"{CONTENT_API_URL}/redaktionsplan", json=payload)
    r.raise_for_status()
    return r.json()


# ─── Strategie ─────────────────────────────────────────────

@tool
def get_strategy(strategy: str = CONTENT_STRATEGY, key: str | None = None) -> dict:
    """Strategie-Daten abrufen (Brand Voice, Channel Rules, etc.).

    :param strategy: Unit-Key
    :param key: Strategie-Key (brand_voice, channel_rules, etc.) — wenn None: alle
    """
    if key:
        r = httpx.get(f"{CONTENT_API_URL}/strategy/{strategy}/{key}")
    else:
        r = httpx.get(f"{CONTENT_API_URL}/strategy/{strategy}")
    r.raise_for_status()
    return r.json()


@tool
def save_strategy(strategy: str, key: str, content: dict) -> dict:
    """Strategie-Eintrag speichern oder aktualisieren (Upsert).

    :param strategy: Unit-Key
    :param key: brand_voice, channel_rules, icp_channel_mapping, media_logic,
                editorial_rhythm, content_strategy, post_templates, content_personas
    :param content: Strategie-Inhalt als Dict
    """
    r = httpx.post(f"{CONTENT_API_URL}/strategy", json={
        "strategy": strategy, "key": key, "content": content,
    })
    r.raise_for_status()
    return r.json()


# ─── Media ─────────────────────────────────────────────────

@tool
def create_media_briefing(
    item_id: str,
    media_type: str,
    prompt: str | None = None,
    generation_params: dict | None = None,
    position: int = 0,
    notes: str | None = None,
    strategy: str = CONTENT_STRATEGY,
) -> dict:
    """Media-Briefing für ein Content-Item anlegen.

    :param item_id: Content-Item-ID
    :param media_type: image, video, graphic, carousel_slide, ad_creative
    :param prompt: Prompt für die Generierung (optional)
    :param generation_params: Generierungsparameter (optional)
    :param position: Position (0 = erstes Bild)
    :param notes: Notizen
    :param strategy: Unit-Key
    """
    payload = {"item_id": item_id, "media_type": media_type, "position": position, "strategy": strategy}
    if prompt: payload["prompt"] = prompt
    if generation_params: payload["generation_params"] = generation_params
    if notes: payload["notes"] = notes
    r = httpx.post(f"{CONTENT_API_URL}/media/briefing", json=payload)
    r.raise_for_status()
    return r.json()


# ─── Alle Tools als Liste ──────────────────────────────────

ALL_TOOLS = [
    create_source, list_sources, get_source_angles,
    create_angle, list_angles, update_angle, get_batch_ranking,
    create_content_idea, produce_content, list_content, update_content, get_overview,
    list_redaktionsplan, create_plan_entry,
    get_strategy, save_strategy,
    create_media_briefing,
]