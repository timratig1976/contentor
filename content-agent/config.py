import os
from dotenv import load_dotenv

load_dotenv()

EDENAI_API_KEY = os.getenv("EDENAI_API_KEY")
CONTENT_API_URL = os.getenv("CONTENT_API_URL", "http://localhost:8000/api")
CONTENT_STRATEGY = os.getenv("CONTENT_STRATEGY", "viscale")

# Default model configs per agent — override via env vars
# reasoning: 'none'|'low'|'medium'|'high' — steuert Reasoning-Aufwand
# (Anthropic: output_config.effort, OpenAI o-Serie/gpt-5: reasoning_effort)
AGENT_MODELS = {
    "research": {
        "provider": os.getenv("RESEARCH_PROVIDER", "anthropic"),
        "model": os.getenv("RESEARCH_MODEL", "claude-sonnet-4-6"),
        "temperature": float(os.getenv("RESEARCH_TEMPERATURE", "0.7")),
        "max_tokens": int(os.getenv("RESEARCH_MAX_TOKENS", "4000")),
        "reasoning": os.getenv("RESEARCH_REASONING", "medium"),
    },
    "angle": {
        "provider": os.getenv("ANGLE_PROVIDER", "anthropic"),
        "model": os.getenv("ANGLE_MODEL", "claude-sonnet-4-6"),
        "temperature": float(os.getenv("ANGLE_TEMPERATURE", "0.5")),
        "max_tokens": int(os.getenv("ANGLE_MAX_TOKENS", "8000")),
        "reasoning": os.getenv("ANGLE_REASONING", "medium"),
    },
    "production": {
        "provider": os.getenv("PRODUCTION_PROVIDER", "anthropic"),
        "model": os.getenv("PRODUCTION_MODEL", "claude-sonnet-4-6"),
        "temperature": float(os.getenv("PRODUCTION_TEMPERATURE", "0.8")),
        "max_tokens": int(os.getenv("PRODUCTION_MAX_TOKENS", "4000")),
        "reasoning": os.getenv("PRODUCTION_REASONING", "medium"),
    },
    "review": {
        "provider": os.getenv("REVIEW_PROVIDER", "openai"),
        "model": os.getenv("REVIEW_MODEL", "gpt-4o"),
        "temperature": float(os.getenv("REVIEW_TEMPERATURE", "0.3")),
        "max_tokens": int(os.getenv("REVIEW_MAX_TOKENS", "3000")),
        "reasoning": os.getenv("REVIEW_REASONING", "low"),
    },
    "coordinator": {
        "provider": os.getenv("COORDINATOR_PROVIDER", "openai"),
        "model": os.getenv("COORDINATOR_MODEL", "gpt-4o"),
        "temperature": float(os.getenv("COORDINATOR_TEMPERATURE", "0.7")),
        "max_tokens": int(os.getenv("COORDINATOR_MAX_TOKENS", "3000")),
        "reasoning": os.getenv("COORDINATOR_REASONING", "low"),
    },
}

# Standard-Workflow-Loops (identisch zum Laravel-Fallback)
DEFAULT_WORKFLOW_LOOPS = [
    {
        "name": "Qualitäts-Loop",
        "from_agent": "review",
        "to_agent": "production",
        "condition": 'verdict = "fail"',
        "max_rounds": 2,
    },
]


def fetch_workflow_loops():
    """Liest Workflow-Loops (Name, From→To, Bedingung, Max-Runden) aus der
    Contentor-DB über die API. Fallback: DEFAULT_WORKFLOW_LOOPS."""
    try:
        import httpx

        r = httpx.get(f"{CONTENT_API_URL}/settings", timeout=5)
        r.raise_for_status()
        loops = r.json().get("workflow_loops")
        if isinstance(loops, list) and loops:
            return loops
    except Exception:
        pass
    return DEFAULT_WORKFLOW_LOOPS