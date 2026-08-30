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
from config import CONTENT_STRATEGY, EDENAI_API_KEY, AGENT_MODELS

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
- Frage den Nutzer, ob er zufrieden ist oder Änderungen wünscht

## QUALITÄTS-LOOP (Feedback-Iteration):
Nach dem REVIEW prüfst du das Verdict:
1. Lies den ```verdict-JSON-Block aus der Review-Antwort.
2. Bei "verdict": "pass" → Workflow abgeschlossen, fasse zusammen.
3. Bei "verdict": "fail" → starte ÜBERARBEITUNGSRUNDE:
   - Rufe produce_content ERNEUT auf mit denselben Angles, aber reiche die
     improvement_instructions aus dem Review als zusaetzlichen Kontext weiter
     (z. B. ueber mechanism/metric/cta oder als Hinweis im Angle).
   - Rufe danach review_content erneut auf.
4. MAXIMAL 2 Überarbeitungsrunden. Wenn danach immer noch "fail":
   brich ab, melde die offenen issues dem Nutzer und frage, wie weiter.
- Zähle die Runden mit und nenne sie in der Zusammenfassung
  (z. B. "Content nach 2 Iterationen freigegeben").

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
        system_prompt=COORDINATOR_SYSTEM_PROMPT,
    ),
    tools=[research_tool, angle_tool, produce_tool, review_tool],
)