<script setup>
import { ref } from 'vue';

/**
 * Interaktive Flow-Übersicht: zeigt den kompletten Weg eines Posts von der
 * Angle-Auswahl bis zum fertigen, geprüften Content-Item. Jeder Schritt ist
 * klickbar und zeigt Detail-Erklärungen — für Nutzer, die verstehen wollen,
 * was beim "Post generieren" im Hintergrund passiert.
 */

const activeStep = ref(null);

function toggle(id) {
    activeStep.value = activeStep.value === id ? null : id;
}

const steps = [
    {
        id: 'angle',
        icon: '🎯',
        title: 'Angle wählen',
        subtitle: 'Kernaussage + ICP + Pain-Cluster',
        color: 'bg-blue-50 border-blue-200 text-blue-700',
        detail: 'Ausgangspunkt ist immer ein bewerteter Angle (die Kernaussage/These). Er bringt ICP (Zielgruppe), Pain-Cluster und ggf. Statement-Typ mit — das ist der inhaltliche Kern, der in jedem Schritt danach erhalten bleibt.',
    },
    {
        id: 'setup',
        icon: '⚙️',
        title: 'Format, Template & Persona',
        subtitle: 'Post-Typ, Muster, Stil',
        color: 'bg-violet-50 border-violet-200 text-violet-700',
        detail: 'Im Editor wählst du: Format (LinkedIn, Blog, Ad Copy, Newsletter...), ein Post-Template/Pattern (z.B. "Data Drop", "Mistake Post") und optional eine Persona (Stil/Stimme). Diese drei Entscheidungen steuern, welche Bausteine gleich in den Prompt einfließen.',
    },
    {
        id: 'prompt',
        icon: '📝',
        title: 'Prompt wird gebaut',
        subtitle: '3 Schichten: Kern · Stil · Ziel',
        color: 'bg-amber-50 border-amber-200 text-amber-700',
        detail: 'Der Prompt entsteht aus 3 Schichten:\n\n① CONTENT-KERN — Angle, ICP, Pain-Cluster, gewähltes Pattern/Template mit Struktur-Vorgabe\n② STIL-LAYER — Persona (Voice, Tonalität, Perspektive, verbotene Wörter, Überzeugungen) + Few-Shot-Referenzposts, falls hinterlegt\n③ ZIEL-LAYER — Kanal-Regeln (Wortzahl, Hook-Länge, Aufbau, CTA-Stil) aus der Strategie\n\nZusätzlich immer dabei: ein "Menschlichkeit"-Block, der typische KI-Textmuster explizit verbietet (z.B. "Das klingt nach X. Es ist Y."-Antithesen, perfekte 3er-Aufzählungen) und eine Absatz-Regel gegen Fließtext-Wände.',
    },
    {
        id: 'llm',
        icon: '🤖',
        title: 'LLM generiert',
        subtitle: 'Konfigurierbares Modell (Agents-Seite)',
        color: 'bg-rose-50 border-rose-200 text-rose-700',
        detail: 'Der Prompt geht an das für "production" konfigurierte Modell (Standard: Claude Sonnet). Modell, Temperature und Max-Tokens sind unter Agents → Production Agent einstellbar. Schlägt der Call fehl, greift ein deterministischer Fallback-Textbaustein, damit nie ein leerer Post entsteht.',
    },
    {
        id: 'tone',
        icon: '🧹',
        title: 'Tonalität erzwingen',
        subtitle: 'Regex-Bereinigung (nicht optional)',
        color: 'bg-teal-50 border-teal-200 text-teal-700',
        detail: 'Direkt nach der Generierung läuft enforceTone(): Verbotene Marken-Formulierungen werden entfernt, Ausrufezeichen zu Punkten gemacht, doppelte/übermäßige Hashtags auf max. 5 gekappt. Das passiert IMMER, unabhängig davon, was das Modell geliefert hat — kein Prompt-Wunsch, sondern Code-Regel.',
    },
    {
        id: 'gate',
        icon: '🛡️',
        title: 'Quality-Gate',
        subtitle: 'Regeln → Auto-Fix → Score',
        color: 'bg-emerald-50 border-emerald-200 text-emerald-700',
        detail: 'Dreistufiger, automatischer Check (max. 2 Korrekturrunden):\n\n1. RULES — Tonalitäts-Verstöße, fehlender Pflicht-CTA, Text zu kurz/lang, Fließtext-Wand (zu wenige Absätze), bekannte KI-Textmuster\n2. FIX — bei Befunden: gezielte Korrektur-Anweisung ans Produktions-Modell (nur der Fund wird behoben, Rest bleibt)\n3. SCORE — ein Review-Modell vergibt 0-10 Punkte + Kommentar, inkl. Einschätzung "klingt das nach einem Menschen?". Wird KI-Sound erkannt, läuft eine gezielte Humanize-Nachbesserung.',
        expandable: true,
        subSteps: [
            { label: 'Regel-Check', desc: 'Verbotene Wörter, Pflicht-CTA, Wortzahl-Grenzen, Absatzstruktur, bekannte KI-Muster (Regex, kein LLM-Call).' },
            { label: 'Auto-Fix (max. 2×)', desc: 'Nur bei Befunden: Produktions-Modell bekommt eine präzise Korrektur-Anweisung, überarbeitet gezielt.' },
            { label: 'LLM-Score', desc: 'Review-Modell bewertet Hook, Mechanismus, Beleg, CTA und Menschlichkeit — 0-10 + Kommentar.' },
            { label: 'Humanize (bei Bedarf)', desc: 'Erkennt das Review-Modell KI-Sound, wird gezielt nachgebessert und neu bewertet.' },
        ],
    },
    {
        id: 'editor',
        icon: '✍️',
        title: 'Editor & Freigabe',
        subtitle: 'Manuell bearbeiten, Assistant-Edit, Output',
        color: 'bg-sky-50 border-sky-200 text-sky-700',
        detail: 'Der fertige Post landet im Editor. Du kannst ihn direkt bearbeiten, per Freitext-Anweisung vom Assistenten überarbeiten lassen ("Mach den Hook schärfer"), als Referenzbeispiel für die Persona speichern oder zum Output/Redaktionsplan freigeben.',
    },
];
</script>

<template>
    <div class="space-y-3">
        <div v-for="(step, i) in steps" :key="step.id">
            <button
                @click="toggle(step.id)"
                class="w-full flex items-center gap-4 p-4 rounded-xl border text-left transition-all"
                :class="activeStep === step.id ? step.color : 'bg-white border-gray-200 hover:border-gray-300'"
            >
                <span class="text-2xl shrink-0">{{ step.icon }}</span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-mono text-gray-400">{{ i + 1 }}</span>
                        <h4 class="text-sm font-semibold text-gray-900">{{ step.title }}</h4>
                    </div>
                    <p class="text-xs text-gray-500 mt-0.5">{{ step.subtitle }}</p>
                </div>
                <span class="text-gray-400 shrink-0" :class="{ 'rotate-180': activeStep === step.id }">▾</span>
            </button>

            <div v-if="activeStep === step.id" class="mt-2 ml-4 pl-8 border-l-2 border-gray-200 pb-2">
                <p class="text-sm text-gray-700 whitespace-pre-line leading-relaxed">{{ step.detail }}</p>

                <div v-if="step.subSteps" class="mt-3 space-y-2">
                    <div v-for="sub in step.subSteps" :key="sub.label" class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                        <p class="text-xs font-semibold text-gray-800">{{ sub.label }}</p>
                        <p class="text-xs text-gray-600 mt-1">{{ sub.desc }}</p>
                    </div>
                </div>
            </div>

            <div v-if="i < steps.length - 1" class="flex justify-center py-0.5">
                <span class="text-gray-300 text-lg">↓</span>
            </div>
        </div>
    </div>
</template>
