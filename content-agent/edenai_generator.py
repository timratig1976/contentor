"""
EdenAI ChatGenerator für Haystack.
Basiert auf Haystacks OpenAIChatGenerator mit EdenAI als OpenAI-kompatibler
API-Basis (https://api.edenai.run/v3). Dadurch funktioniert echtes Tool-Calling
(function calling) — Haystack-Agents können iterativ Tools aufrufen,
Ergebnisse verarbeiten und weiterarbeiten.
"""
from typing import Optional

from haystack.components.generators.chat import OpenAIChatGenerator
from haystack.utils import Secret


EDENAI_API_BASE = "https://api.edenai.run/v3"


def _is_openai_reasoning_model(model: str) -> bool:
    """Erkennt OpenAI-Modelle mit reasoning_effort (o-Serie, gpt-5)."""
    import re

    return bool(re.search(r"\b(o1|o3|o4|gpt-5)", model.lower()))


class EdenAIChatGenerator(OpenAIChatGenerator):
    """
    Chat-Generator über EdenAI (OpenAI-kompatibel) — ein API-Key für alle Modelle.

    :param edenai_api_key: EdenAI API Key
    :param provider: Provider (openai, google, anthropic, mistral, etc.)
    :param model: Modell-Name (gpt-4o, claude-sonnet-4-6, gemini-3.7-flash, etc.)
    :param temperature: Kreativität (0.0 - 1.0)
    :param max_tokens: Maximale Antwortlänge (inkl. Thinking-Tokens bei Claude!)
    :param reasoning: Reasoning-Aufwand ('none'|'low'|'medium'|'high').
        Anthropic (adaptives Thinking): wird als output_config.effort gesendet.
        OpenAI o-Serie/gpt-5: wird als reasoning_effort gesendet.
        Andere Modelle: ignoriert.
    :param system_prompt: Wird vom Haystack-Agent injiziert — hier nur zur
        Dokumentation; direkte Generator-Nutzung übergibt ihn als System-Message.
    """

    def __init__(
        self,
        edenai_api_key: str,
        provider: str = "openai",
        model: str = "gpt-4o",
        temperature: float = 0.7,
        max_tokens: int = 2000,
        reasoning: str = "none",
        system_prompt: Optional[str] = None,
    ):
        self.provider = provider
        self.system_prompt = system_prompt

        # Doppelten Provider-Präfix entfernen ("google/gemini-…" bei provider="google")
        clean_model = model[len(provider) + 1:] if model.startswith(f"{provider}/") else model
        self.clean_model = clean_model

        generation_kwargs = {
            "temperature": temperature,
            "max_tokens": max_tokens,
        }

        # Reasoning-Parameter provider-spezifisch anwenden
        reasoning = (reasoning or "none").lower()
        if reasoning in ("low", "medium", "high"):
            if provider == "anthropic":
                generation_kwargs["output_config"] = {"effort": reasoning}
            elif provider in ("openai", "azure") and _is_openai_reasoning_model(clean_model):
                generation_kwargs["reasoning_effort"] = reasoning
            elif provider == "google":
                budgets = {"low": 128, "medium": 1024, "high": 4096}
                generation_kwargs["thinking_config"] = {"thinking_budget": budgets[reasoning]}

        super().__init__(
            api_key=Secret.from_token(edenai_api_key),
            api_base_url=EDENAI_API_BASE,
            # EdenAI v3 erwartet Modell-IDs im Format "provider/modell"
            model=f"{provider}/{clean_model}",
            generation_kwargs=generation_kwargs,
            timeout=180,
            max_retries=2,
        )
