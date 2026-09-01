import os
from dotenv import load_dotenv

load_dotenv()

EDENAI_API_KEY = os.getenv("EDENAI_API_KEY")
SERPERDEV_API_KEY = os.getenv("SERPERDEV_API_KEY")
CONTENT_API_URL = os.getenv("CONTENT_API_URL", "http://localhost:8000/api")
CONTENT_STRATEGY = os.getenv("CONTENT_STRATEGY", "viscale")

# Default model configs per agent — override via env vars
AGENT_MODELS = {
    "research": {
        "provider": os.getenv("RESEARCH_PROVIDER", "openai"),
        "model": os.getenv("RESEARCH_MODEL", "gpt-4o"),
        "temperature": float(os.getenv("RESEARCH_TEMPERATURE", "0.7")),
        "max_tokens": int(os.getenv("RESEARCH_MAX_TOKENS", "2000")),
    },
    "angle": {
        "provider": os.getenv("ANGLE_PROVIDER", "openai"),
        "model": os.getenv("ANGLE_MODEL", "gpt-4o"),
        "temperature": float(os.getenv("ANGLE_TEMPERATURE", "0.5")),
        "max_tokens": int(os.getenv("ANGLE_MAX_TOKENS", "1500")),
    },
    "production": {
        "provider": os.getenv("PRODUCTION_PROVIDER", "anthropic"),
        "model": os.getenv("PRODUCTION_MODEL", "claude-3-5-sonnet-20240620"),
        "temperature": float(os.getenv("PRODUCTION_TEMPERATURE", "0.8")),
        "max_tokens": int(os.getenv("PRODUCTION_MAX_TOKENS", "3000")),
    },
    "review": {
        "provider": os.getenv("REVIEW_PROVIDER", "openai"),
        "model": os.getenv("REVIEW_MODEL", "gpt-4o"),
        "temperature": float(os.getenv("REVIEW_TEMPERATURE", "0.3")),
        "max_tokens": int(os.getenv("REVIEW_MAX_TOKENS", "1500")),
    },
    "coordinator": {
        "provider": os.getenv("COORDINATOR_PROVIDER", "openai"),
        "model": os.getenv("COORDINATOR_MODEL", "gpt-4o"),
        "temperature": float(os.getenv("COORDINATOR_TEMPERATURE", "0.7")),
        "max_tokens": int(os.getenv("COORDINATOR_MAX_TOKENS", "2000")),
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