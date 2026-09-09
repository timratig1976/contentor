"""
Coordinator Agent — orchestriert den gesamten Content-Produktions-Workflow.
Nutzt Research, Angle, Production und Review Agenten als Tools.
"""
from haystack.components.agents import Agent
from haystack.tools import AgentTool

from edenai_generator import EdenAIChatGenerator
from agents.research_agent import research_agent
from agents.angle_agent import angle_agent
from agents.production_agent import production_agent
from agents.review_agent import review_agent
from config import CONTENT_STRATEGY, EDENAI_API_KEY, AGENT_MODELS, fetch_workflow_loops


def _workflow_loops_config(loops):
    """Erzeugt den loop-spezifischen Abschnitt des Coordinator-Prompts
    aus den in der UI konfigurierten Loops (workflow_loops)."""
    if not loops:
        return (
            "Keine Feedback-Loops konfiguriert. Führe den Workflow "
            "strikt einmalig aus (Phase 1-4) und brich bei Fehlern ab."
        )
    lines = []
    for i, l in enumerate(loops, 1):
        frm = l.get("from_agent", "review")
        to = l.get("to_agent", "production")
        cond = l.get("condition") or "die Bedingung aus der UI-Konfiguration zutrifft"
        lines.append(f"{i}. **{l.get('name') or 'Loop ' + str(i)}**")
        lines.append(f"   - Prüfe nach `{frm}`, ob {cond}.")
        lines.append(
            f"   - Falls ja: rufe `{to}` ERNEUT auf und reiche das Feedback aus `{frm}` "
            f"als zusätzlichen Kontext weiter. Danach wiederhole `{frm}`."
        )
        lines.append(
            f"   - MAXIMAL {l.get('max_rounds', 1)} Überarbeitungsrunden. Falls danach die "
            f"Bedingung immer noch zutrifft: brich ab, melde die offenen Issues dem "
            f"Nutzer und frage, wie weiter."
        )
    return "\n".join(lines)

# Jeden Sub-Agent als Tool verpacken
research_tool = AgentTool(
    agent=research_agent,
    name="research",
    description="Recherchiere ein Thema: durchsuche das Web, speichere Quellen und extrahiere erste Angles. Nutze dies für neue Themen-Recherchen.",
)

angle_tool = AgentTool(
    agent=angle_agent,
    name="develop_angles",
    description="Analysiere, bewerte und ranke Angles. Lade Strategie-Daten, vergebe Scores (1-3) für Zielgruppe, Viscale-Fit, Schärfe und Timing. Nutze dies nach der Recherche oder wenn Angles bewertet werden müssen.",
)

produce_tool = AgentTool(
    agent=production_agent,
    name="produce_content",
    description="Produziere Content aus Top-Angles. Generiere LinkedIn-Posts, Ad Copies, Newsletter oder Landing-Page-Headlines basierend auf Brand Voice und Templates. Nutze dies, wenn Angles bereit zur Produktion sind.",
)

review_tool = AgentTool(
    agent=review_agent,
    name="review_content",
    description="Prüfe produzierten Content gegen Brand Voice, Channel Rules und Qualitätskriterien. Gib konkretes Feedback und wende Verbesserungen an. Nutze dies nach der Content-Produktion.",
)

# Loops werden bei Import aus der UI-Konfiguration (workflow_loops) geladen
_LOOP_CONFIG = _workflow_loops_config(fetch_workflow_loops())

COORDINATOR_SYSTEM_PROMPT = f"""
Du bist ein Content-Strategie-Koordinator für die Unit "{CONTENT_STRATEGY}".
Du orchestrierst den gesamten Content-Produktions-Workflow von der Recherche
bis zum fertigen, reviewten Content.

## Deine Sub-Agenten:
- **research**: Recherchiert Themen, findet Quellen, extrahiert Angles
- **develop_angles**: Bewertet und ranked Angles nach 4 Kriterien
- **produce_content**: Produziert Content aus Top-Angles
- **review_content**: Prüft und verbessert produzierten Content

## Workflow:
Für jede Anfrage durchläufst du diese Phasen:

1. **RESEARCH** → rufe `research` mit dem Thema auf
2. **DEVELOP** → rufe `develop_angles` auf, um die Angles zu ranken
3. **PRODUCE** → rufe `produce_content` auf mit den Top-Angles
4. **REVIEW** → rufe `review_content` auf zur Qualitätssicherung

## Wichtige Regeln:
- Führe die Phasen IN REIHENFOLGE aus (nicht parallel)
- Warte das Ergebnis jeder Phase ab, bevor du die nächste startest
- Wenn eine Phase scheitert, brich ab und melde das Problem
- Am Ende gib eine Zusammenfassung: Was wurde produziert, für welchen Kanal, mit welchem Score
  Nenne dabei die durchlaufenen Runden/Loops (z. B. "Content nach 2 Iterationen freigegeben").
- Frage den Nutzer, ob er zufrieden ist oder Änderungen wünscht

## QUALITÄTS-LOOP (Feedback-Iteration):
_Loop-Konfiguration (aus der UI):_
{_LOOP_CONFIG}

## Content-Formate:
- linkedin_post: Thought-Leadership-Post
- ad_copy: Paid-Social-Ad (Meta/LinkedIn)
- newsletter_acquisition: Newsletter zur Lead-Generierung
- newsletter_bk: BK-Newsletter
- landing_page_headlines: Landing-Page-Headlines

## Beispiel-Dialog:
User: "Recherchiere zum Thema CRM-Datenqualität und produziere LinkedIn-Posts"
→ Du startest Phase 1 (research), dann Phase 2 (develop_angles), etc.
"""

mc = AGENT_MODELS["coordinator"]

coordinator = Agent(
    chat_generator=EdenAIChatGenerator(
        edenai_api_key=EDENAI_API_KEY,
        provider=mc["provider"],
        model=mc["model"],
        temperature=mc["temperature"],
        max_tokens=mc["max_tokens"],
        reasoning=mc.get("reasoning", "none"),
        system_prompt=COORDINATOR_SYSTEM_PROMPT,
    ),
    tools=[research_tool, angle_tool, produce_tool, review_tool],
)