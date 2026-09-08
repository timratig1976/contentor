"""
Debug-Runner für den Contentor Multi-Agent-Workflow.

Unterschied zu main.py: Zeigt den KOMPLETTEN Datenfluss durch die Agenten —
System-Prompts, jede Tool-Aufrufe (Name + Argumente), jedes Tool-Ergebnis
und die Zwischen-Entscheidungen pro Phase. Schreibt zusätzlich eine
maschinenlesbare JSON-Trace nach trace_output.json.

Nutzung:
    cd content-agent
    python3 debug_run.py "Recherchiere zum Thema CRM-Datenqualität"
    # oder interaktiv (ohne Argument):
    python3 debug_run.py
"""
import sys
import json
import os
from datetime import datetime
from dotenv import load_dotenv

from haystack.dataclasses import ChatMessage

from agents.coordinator import coordinator
from agents.research_agent import RESEARCH_SYSTEM_PROMPT
from agents.angle_agent import ANGLE_SYSTEM_PROMPT
from agents.production_agent import PRODUCTION_SYSTEM_PROMPT
from agents.review_agent import REVIEW_SYSTEM_PROMPT

PROMPTS = {
    "research": RESEARCH_SYSTEM_PROMPT,
    "angle": ANGLE_SYSTEM_PROMPT,
    "production": PRODUCTION_SYSTEM_PROMPT,
    "review": REVIEW_SYSTEM_PROMPT,
}

ANSI = {"dim": "\033[2m", "bold": "\033[1m", "green": "\033[32m",
        "blue": "\033[34m", "yellow": "\033[33m", "reset": "\033[0m"}


def c(text: str, color: str) -> str:
    return f"{ANSI.get(color, '')}{text}{ANSI['reset']}"


def _summarize(value, limit: int = 2000) -> str:
    """Macht Tool-Ergebnisse (Dicts/Lists) lesbar und begrenzt die Länge."""
    if isinstance(value, str):
        text = value
    else:
        try:
            text = json.dumps(value, ensure_ascii=False, indent=2, default=str)
        except Exception:
            text = str(value)
    if len(text) > limit:
        text = text[:limit] + f"\n… [gekürzt, gesamt {len(text)} Zeichen]"
    return text


def _tool_arguments(tool_call) -> dict:
    """Extrahiert die Argumente eines ToolCalls defensiv (Haystack Dataclass)."""
    return getattr(tool_call, "arguments", None) or {}


def _tool_result_payload(result) -> str:
    """Gibt ToolCallResult als lesbaren Text zurück."""
    if getattr(result, "error", None):
        return f"ERROR: {result.error}"
    return _summarize(getattr(result, "result", None))


def build_trace(result) -> list:
    """
    Rekonstruiert aus Haystacks zurückgegebenen Messages eine
    schrittweise, lesbare Spur des gesamten Laufs.
    """
    steps = []
    for msg in result.get("messages", []):
        role = getattr(msg, "role", None)

        if role == "user":
            steps.append({"role": "user", "text": msg.text})
            continue

        if role == "assistant":
            if getattr(msg, "text", None):
                steps.append({"role": "assistant", "text": msg.text})
            for tc in getattr(msg, "tool_calls", None) or []:
                steps.append({
                    "role": "tool_call",
                    "tool": getattr(tc, "tool_name", "?"),
                    "arguments": _tool_arguments(tc),
                })
            continue

        if role == "tool":
            for tr in getattr(msg, "tool_call_results", None) or []:
                steps.append({
                    "role": "tool_result",
                    "origin": getattr(tr, "origin", "?"),
                    "result": getattr(tr, "result", None),
                    "error": getattr(tr, "error", None),
                })
            continue

    return steps


def print_trace(steps: list):
    print("\n" + "=" * 70)
    print(c("TRACE — kompletter Datenfluss durch die Agenten", "bold"))
    print("=" * 70)

    for s in steps:
        role, text = s["role"], s.get("text")
        if role == "user":
            print(f"\n{c('👤 USER', 'bold')}:\n{text}")
        elif role == "assistant":
            print(f"\n{c('🤖 ASSISTANT (Entscheidung)', 'bold')}:\n{text}")
        elif role == "tool_call":
            tool_name = s["tool"]
            print(f"\n" + c(f"🔧 TOOL-CALL → {tool_name}", "blue") + ":")
            print(c("   Argumente:", "dim"))
            print(_summarize(s["arguments"], 1500))
        elif role == "tool_result":
            if s.get("error"):
                print(c(f"   ↳ ERGEBNIS (FEHLER):", "yellow"))
            else:
                print(c("   ↳ ERGEBNIS:", "dim"))
            print(_summarize(s.get("result") or s.get("error"), 2000))


def run(user_input: str):
    print(c(f"Starte Workflow mit Input: {user_input}", "green"))
    result = coordinator.run(
        messages=[ChatMessage.from_user(user_input)],
    )

    steps = build_trace(result)

    # 1) Lesbare Konsole
    print_trace(steps)

    # 2) Maschinenlesbare JSON-Trace
    out = {
        "generated_at": datetime.now().isoformat(),
        "input": user_input,
        "system_prompts": PROMPTS,
        "steps": steps,
        "last_message": getattr(result.get("last_message"), "text", None),
    }
    path = os.path.join(os.path.dirname(__file__), "trace_output.json")
    with open(path, "w", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False, indent=2, default=str)
    print("\n" + c(f"✅ Vollständige Trace geschrieben nach: {path}", "green"))
    print(c("   Öffnen & mit den Agent-Prompts vergleichen, um sie zu verbessern.", "dim"))


def main():
    load_dotenv()
    if not os.getenv("EDENAI_API_KEY"):
        print("❌ EDENAI_API_KEY nicht gesetzt. Bitte in content-agent/.env eintragen.")
        sys.exit(1)

    user_input = " ".join(sys.argv[1:]).strip()
    if not user_input:
        # Interaktiv abfragen
        user_input = input("👤 Deine Aufgabe für den Workflow: ").strip()
        if not user_input:
            print("Keine Eingabe — beende.")
            sys.exit(0)

    run(user_input)


if __name__ == "__main__":
    main()