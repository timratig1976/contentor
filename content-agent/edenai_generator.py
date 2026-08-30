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


class EdenAIChatGenerator(OpenAIChatGenerator):
    """
    Chat-Generator über EdenAI (OpenAI-kompatibel) — ein API-Key für alle Modelle.

    :param edenai_api_key: EdenAI API Key
    :param provider: Provider (openai, google, anthropic, mistral, etc.)
    :param model: Modell-Name (gpt-4o, claude-sonnet-4-6, gemini-3.7-flash, etc.)
    :param temperature: Kreativität (0.0 - 1.0)
    :param max_tokens: Maximale Antwortlänge
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
        system_prompt: Optional[str] = None,
    ):
        self.provider = provider
        self.system_prompt = system_prompt

        # Doppelten Provider-Präfix entfernen ("google/gemini-…" bei provider="google")
        clean_model = model[len(provider) + 1:] if model.startswith(f"{provider}/") else model
        self.clean_model = clean_model

        super().__init__(
            api_key=Secret.from_token(edenai_api_key),
            api_base_url=EDENAI_API_BASE,
            # EdenAI v3 erwartet Modell-IDs im Format "provider/modell"
            model=f"{provider}/{clean_model}",
            generation_kwargs={
                "temperature": temperature,
                "max_tokens": max_tokens,
            },
            timeout=180,
            max_retries=2,
        )
