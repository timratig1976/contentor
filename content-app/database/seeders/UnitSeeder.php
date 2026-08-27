<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        Unit::create([
            'key' => 'viscale',
            'name' => 'viscale',
            'config' => [
                'rules' => [
                    'icpKeys' => ['B2B-1', 'B2B-2', 'B2B-3', 'B2C', 'UNI', 'BK'],
                    'hashtags' => ['#viscale', '#Vertriebssystem', '#HubSpot', '#Mittelstand'],
                    'signoff' => 'das viscale-Team',
                    'editorialPlanTitle' => 'Redaktionsplan · viscale',
                    'defaultOwner' => 'viscale',
                    'clusters' => [
                        ['key' => 'cluster_1', 'code' => 'E3-02', 'name' => 'Vertrieb hängt an Personen', 'match' => 'person|vertriebsleiter|kopf'],
                        ['key' => 'cluster_2', 'code' => 'E3-01', 'name' => 'Blindflug im Forecast', 'match' => 'forecast|pipeline|signen|unterschreiben'],
                        ['key' => 'cluster_3', 'code' => 'E3-03', 'name' => 'Leads versickern unbemerkt', 'match' => 'lead|versick|nachverfolg'],
                        ['key' => 'cluster_4', 'code' => 'E3-05', 'name' => 'Wachstum wird teurer statt effizienter', 'match' => 'teuer|effizienz|cac|wachs'],
                        ['key' => 'cluster_5', 'code' => 'E3-06', 'name' => 'Datenchaos & fehlende Datenhygiene', 'match' => 'daten|property|chaos|hygiene'],
                        ['key' => 'cluster_6', 'code' => 'E1-02', 'name' => 'KI-Druck ohne Fundament', 'match' => 'ki|ai|breeze'],
                    ],
                    'defaultClusterKey' => 'cluster_2',
                    'icpGuesser' => [
                        ['icp' => 'BK', 'match' => 'bestand|renewal|adoption|retainer'],
                        ['icp' => 'UNI', 'match' => 'uni|hochschule|forschung'],
                        ['icp' => 'B2C', 'match' => 'b2c|consumer|ecommerce'],
                        ['icp' => 'B2B-2', 'match' => 'forecast|pipeline|head of sales|sales'],
                        ['icp' => 'B2B-3', 'match' => 'crm|chaos|struktur|hubspot'],
                    ],
                    'defaultIcp' => 'B2B-1',
                    'harvestCandidatePattern' => 'crm|forecast|lead|hubspot|pipeline',
                    'forbiddenPatterns' => [
                        '!', 'beste[nrsm]?', 'einzigartig(?:e|er|es|en)?',
                        'revolution[aä]r(?:e|er|es|en)?',
                        'dein vertrieb kann mehr', 'verbessere deinen vertrieb',
                    ],
                    'genericBkPatterns' => [
                        'wir freuen uns', 'spannend(?:e|er|es|en)?',
                        'interessant(?:e|er|es|en)?', 'bucht jetzt', 'demo anfragen',
                    ],
                    'toneLabel' => 'viscale-Tonalitätsregeln',
                    'monitoring' => [
                        'gaps' => [
                            'B2B-2 Forecast-Beweislast wird im Markt meist nur oberflächlich adressiert.',
                            'Niemand besetzt aktuell die Kombination aus HubSpot-Mechanismus und Führungslogik konsequent.',
                        ],
                        'gapLine' => 'Lücken für viscale herausarbeiten',
                        'toneGapHint' => 'Danach Lücken gegen viscale-Tonalität spiegeln.',
                    ],
                    'bk' => [
                        'defaultTopic' => 'Property-Hygiene · 3 Fehler die wir oft sehen',
                        'preheader' => 'Konkrete Einordnung aus Delivery und ein nächster Schritt in HubSpot.',
                        'mainCta' => 'Prüft diese Woche eine aktive Pipeline auf fehlende Pflichtfelder und antwortet auf diese Mail, wenn ihr die Logik gemeinsam durchgehen wollt.',
                        'pathCheck' => 'Settings → Objects → Deals → Pipelines',
                        'topics' => [
                            'Revenue Hub · was sich für euch ändert',
                            'Property-Hygiene · 3 Fehler die wir oft sehen',
                            'Renewal-Pipeline · der unterschätzte Hebel',
                        ],
                    ],
                ],
            ],
        ]);

        Unit::create([
            'key' => 'vitalents',
            'name' => 'vitalents',
            'config' => [
                'rules' => [
                    'icpKeys' => ['B2B-1', 'B2B-2', 'B2B-3', 'B2C', 'UNI', 'BK'],
                    'hashtags' => ['#vitalents', '#Recruiting', '#Klinik', '#Pflege'],
                    'signoff' => 'das vitalents-Team',
                    'editorialPlanTitle' => 'Redaktionsplan · vitalents',
                    'defaultOwner' => 'vitalents',
                    'clusters' => [
                        ['key' => 'cluster_1', 'code' => 'V1-01', 'name' => 'Fachkräftemangel in Klinik und Pflege', 'match' => 'fachkr|pflege|klinik|personal'],
                        ['key' => 'cluster_2', 'code' => 'V1-02', 'name' => 'Recruiting-Prozesse ohne System', 'match' => 'recruit|bewerb|prozess|system'],
                        ['key' => 'cluster_3', 'code' => 'V1-03', 'name' => 'Kandidaten springen im Prozess ab', 'match' => 'kandidat|absprung|abbruch'],
                        ['key' => 'cluster_4', 'code' => 'V1-04', 'name' => 'Employer Branding ohne Beweislast', 'match' => 'employer|brand|image'],
                        ['key' => 'cluster_5', 'code' => 'V1-05', 'name' => 'Datenchaos im Bewerbermanagement', 'match' => 'daten|ats|chaos|hygiene'],
                    ],
                    'defaultClusterKey' => 'cluster_1',
                    'icpGuesser' => [
                        ['icp' => 'BK', 'match' => 'bestand|renewal|adoption|retainer'],
                        ['icp' => 'B2B-2', 'match' => 'klinik|pflege|recruiting|personal'],
                    ],
                    'defaultIcp' => 'B2B-1',
                    'harvestCandidatePattern' => 'recruiting|pflege|klinik|kandidat|fachkr',
                    'forbiddenPatterns' => [
                        '!', 'beste[nrsm]?', 'einzigartig(?:e|er|es|en)?',
                        'revolution[aä]r(?:e|er|es|en)?',
                    ],
                    'genericBkPatterns' => [
                        'wir freuen uns', 'spannend(?:e|er|es|en)?',
                        'interessant(?:e|er|es|en)?', 'bucht jetzt', 'demo anfragen',
                    ],
                    'toneLabel' => 'vitalents-Tonalitätsregeln',
                    'monitoring' => [
                        'gaps' => [
                            'Recruiting-Prozesse werden im Markt selten mit Systemlogik adressiert.',
                            'Die Kombination aus Pflege-Fachkräftemangel und Prozesssteuerung ist kaum besetzt.',
                        ],
                        'gapLine' => 'Lücken für vitalents herausarbeiten',
                        'toneGapHint' => 'Danach Lücken gegen vitalents-Tonalität spiegeln.',
                    ],
                    'bk' => [
                        'defaultTopic' => 'Bewerber-Hygiene · 3 Fehler die wir oft sehen',
                        'preheader' => 'Konkrete Einordnung aus Delivery und ein nächster Schritt im System.',
                        'mainCta' => 'Prüft diese Woche einen aktiven Recruiting-Prozess und antwortet auf diese Mail, wenn ihr die Logik gemeinsam durchgehen wollt.',
                        'pathCheck' => null,
                        'topics' => [
                            'Kandidaten-Kommunikation · wo Prozesse brechen',
                            'Bewerber-Hygiene · 3 Fehler die wir oft sehen',
                            'Onboarding-Pipeline · der unterschätzte Hebel',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
