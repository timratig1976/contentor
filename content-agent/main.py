"""
Contentor Agent CLI — Starte den Content-Produktions-Workflow per Chat.
"""
import os
import sys
from dotenv import load_dotenv

from haystack.dataclasses import ChatMessage
from haystack.components.generators.utils import print_streaming_chunk

from agents.coordinator import coordinator


def main():
    load_dotenv()

    if not os.getenv("EDENAI_API_KEY"):
        print("❌ EDENAI_API_KEY nicht gesetzt. Bitte in .env eintragen.")
        sys.exit(1)

    print("=" * 60)
    print("🤖  Contentor Agent — Multi-Agent Content Workflow")
    print("=" * 60)
    print()
    print("Gib dein Thema oder deine Anfrage ein (z. B.):")
    print('  "Recherchiere zum Thema CRM-Datenqualität und produziere LinkedIn-Posts"')
    print('  "Recherchiere Forecast-Pains für die Persona <Name>"  (zielt nur auf diese Persona)')
    print('  "Analysiere und ranke alle Angles im Batch crm-trends-2026"')
    print('  "Produziere Content aus den Top-3-Angles als Newsletter"')
    print()
    print("Gib 'exit' ein zum Beenden.")
    print("-" * 60)

    while True:
        try:
            user_input = input("\n👤 Du: ").strip()
        except (EOFError, KeyboardInterrupt):
            print("\n👋 Tschüss!")
            break

        if user_input.lower() in ("exit", "quit", "q"):
            print("👋 Tschüss!")
            break

        if not user_input:
            continue

        print("\n🤖 Agent: ", end="", flush=True)
        result = coordinator.run(
            messages=[ChatMessage.from_user(user_input)],
            streaming_callback=print_streaming_chunk,
        )

        # Falls kein Streaming-Output, print last_message
        last_msg = result.get("last_message")
        if last_msg and last_msg.text:
            # Streaming hat schon geprinted, aber sicherheitshalber
            pass

        print("\n" + "-" * 60)


if __name__ == "__main__":
    main()