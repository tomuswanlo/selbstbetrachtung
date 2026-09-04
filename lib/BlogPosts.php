<?php
declare(strict_types=1);

/**
 * Blog-Artikel für Selbstbetrachtung.
 *
 * Kein Admin-Panel/keine Datenbank bewusst (siehe Projekt-Notizen) — neue Artikel
 * werden hier direkt als Array-Eintrag ergänzt. Jeder Artikel vertieft in der Regel
 * einen kurzen Instagram-/Facebook-Post; "source_label"/"source_url" sind optional
 * und verlinken dahin zurück, falls vorhanden.
 *
 * Neuer Artikel: einfach ein weiteres Element vorne in ALL() einfügen (neueste zuerst)
 * und einen eindeutigen "slug" vergeben (nur a-z, 0-9, Bindestriche). Nach dem Hinzufügen
 * bitte auch sitemap.xml um die neue URL ergänzen.
 */
final class BlogPosts
{
    /**
     * @return array<int,array{
     *   slug:string, title:string, date:string, excerpt:string, body:string,
     *   image:?string, image_alt:?string, source_label:?string, source_url:?string
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'slug' => 'gesunde-gewohnheiten-warum-wissen-nicht-reicht',
                'title' => '85,6 statt 81,4 Jahre: Warum Wissen allein nicht reicht',
                'date' => '2026-09-04',
                'excerpt' => 'Eine Studie zeigt: Wir wünschen uns 85,6 Lebensjahre, erreichen aber nur 81,4. Ein Gedanke dazu, den ich vor Kurzem geteilt habe – hier etwas ausführlicher.',
                'image' => null,
                'image_alt' => null,
                'source_label' => null,
                'source_url' => null,
                'body' => <<<'HTML'
<p>Wir wollen im Schnitt 85,6 Jahre alt werden. Erreicht wird davon im Schnitt nur ein Alter von 81,4 Jahren – eine Lücke von über vier Jahren. Das zeigt eine aktuelle Studie des Nuremberg Institute for Market Decisions (NIM) zu „Longevity zwischen Anspruch und Alltag". Die eigentlich interessante Zahl steckt aber nicht im Altersunterschied, sondern in der Erklärung dahinter – ein Gedanke, den ich dazu vor Kurzem kurz geteilt hatte, möchte ich hier etwas vertiefen.</p>

<h2>Die Lücke zwischen Wunsch und Wirklichkeit</h2>
<p>53 % der Befragten leben laut der Studie bewusst im Moment statt langfristig gesundheitsbewusst. Nur 22 % beschreiben sich selbst als wirklich diszipliniert. Dabei sind viele einzelne gesunde Gewohnheiten – ausreichend Schlaf, Bewegung, soziale Kontakte – längst vorhanden. Was fehlt, ist offenbar nicht das Wissen. Was fehlt, ist etwas anderes.</p>

<h2>Warum Wissen allein nicht reicht</h2>
<p>Fast jeder weiß, was guttut: mehr Bewegung, besserer Schlaf, weniger Stress, echte soziale Kontakte. Trotzdem klafft zwischen Wunsch und gelebtem Alltag eine deutliche Lücke. Es scheitert selten am fehlenden Wissen – ein weiterer Ernährungsplan, eine weitere App, ein weiterer Ratgeber ändert daran meist wenig. Es scheitert an der inneren Haltung: an Gewohnheiten, die sich über Jahre eingeschliffen haben, und vor allem an der Frage, warum man überhaupt etwas verändern möchte. Ohne einen echten, persönlichen Grund bleibt jede Veränderung ein Vorsatz – und Vorsätze sind bekanntlich kurzlebig.</p>

<h2>Es geht um Motivation, nicht um einen weiteren Plan</h2>
<p>Ein Plan sagt, was zu tun ist. Er beantwortet aber selten, warum es bisher nicht gelungen ist, danach zu leben – und was im Alltag, im Selbstbild oder in bisherigen Erfahrungen eigentlich im Weg steht. Diese Fragen lassen sich nicht mit noch mehr Fachwissen lösen, sondern nur im echten Gespräch mit sich selbst – oder mit jemandem, der dabei unterstützt, ehrlich hinzuschauen.</p>

<p>Genau daran setzt psychologische Beratung an: nicht bei der Frage, was gesund ist, sondern bei der Frage, was Sie persönlich davon abhält, danach zu handeln. Das kann die eigene Motivation sein, ein festgefahrenes Selbstbild, alte Gewohnheiten oder auch die Angst vor Veränderung selbst. Wenn Sie das Gefühl haben, genau an diesem Punkt festzustecken: Ein <a href="/#kontakt">kostenloses, unverbindliches Erstgespräch</a> ist ein guter erster Schritt.</p>
HTML,
            ],
            [
                'slug' => 'wenn-der-kopf-nicht-abschaltet',
                'title' => 'Wenn der Kopf nicht abschaltet: 3 Fragen, die bei innerer Unruhe helfen',
                'date' => '2026-08-19',
                'excerpt' => 'Die Gedanken kreisen, der Feierabend will nicht ruhig werden. Ein kurzer Impuls dazu, den ich vor Kurzem geteilt habe – hier etwas ausführlicher.',
                'image' => null,
                'image_alt' => null,
                'source_label' => null,
                'source_url' => null,
                'body' => <<<'HTML'
<p>Der Tag ist eigentlich vorbei, aber im Kopf läuft er weiter. Die E-Mail, die noch offen war. Das Gespräch, das anders hätte laufen können. Der Gedanke an morgen, übermorgen, die ganze Woche. Innere Unruhe meldet sich oft genau dann, wenn eigentlich Ruhe angesagt wäre.</p>

<p>Ein kleiner Impuls, den ich neulich in einem kurzen Beitrag geteilt hatte, möchte ich hier etwas vertiefen: drei Fragen, die helfen können, wenn die Gedanken nicht zur Ruhe kommen wollen.</p>

<h2>1. Worum geht es eigentlich – und worum nicht?</h2>
<p>Kreisende Gedanken vermischen oft mehrere Dinge gleichzeitig: die eigentliche Sache, die Sorge davor, was andere denken könnten, und alte, ähnliche Situationen, die plötzlich wieder hochkommen. Schon die einfache Frage „Was genau beschäftigt mich gerade – und was gehört eigentlich nicht dazu?" schafft oft erste Distanz.</p>

<h2>2. Was davon liegt in meiner Hand – und was nicht?</h2>
<p>Ein Teil der Unruhe entsteht dadurch, dass wir versuchen, Dinge zu kontrollieren, die wir gar nicht beeinflussen können. Die ehrliche Trennung zwischen „das kann ich heute noch tun" und „das liegt gerade nicht in meiner Hand" nimmt oft schon einen Teil des Drucks raus.</p>

<h2>3. Was würde mir jetzt, in diesem Moment, guttun?</h2>
<p>Nicht: was sollte ich tun. Sondern: was würde tatsächlich helfen – ein kurzer Spaziergang, ein Gespräch, aufschreiben, was im Kopf ist, oder einfach bewusst nichts tun. Diese Frage holt aus dem Gedankenkreisen zurück in den Moment.</p>

<p>Diese drei Fragen ersetzen kein Gespräch und keine Beratung – aber sie können ein erster, kleiner Schritt sein, wenn der Kopf abends nicht zur Ruhe kommt. Falls das bei Ihnen öfter der Fall ist und Sie das Gefühl haben, tiefer daran arbeiten zu wollen: Ein <a href="/#kontakt">kostenloses, unverbindliches Erstgespräch</a> ist ein guter, niedrigschwelliger Einstieg.</p>
HTML,
            ],
            [
                'slug' => 'selbstreflexion-ist-mehr-als-gruebeln',
                'title' => 'Warum Selbstreflexion mehr ist als Grübeln',
                'date' => '2026-08-12',
                'excerpt' => 'Nachdenken über sich selbst und im Kreis grübeln fühlen sich manchmal ähnlich an – sind aber grundverschieden. Ein Gedanke aus einem Social-Media-Post, hier weitergesponnen.',
                'image' => null,
                'image_alt' => null,
                'source_label' => null,
                'source_url' => null,
                'body' => <<<'HTML'
<p>„Ich denke ständig über alles nach – bin ich damit schon selbstreflektiert?" Diese Frage kam sinngemäß in einer Reaktion auf einen kurzen Post, den ich vor Kurzem veröffentlicht hatte. Sie trifft einen wichtigen Punkt, den ich hier gerne etwas ausführlicher aufgreife.</p>

<h2>Der feine, aber entscheidende Unterschied</h2>
<p>Grübeln und Selbstreflexion fühlen sich von innen oft ähnlich an: Man beschäftigt sich mit sich selbst, mit einer Situation, einem Gefühl. Der Unterschied zeigt sich aber daran, wohin es führt.</p>
<p>Grübeln dreht sich meist im Kreis: dieselbe Frage, dieselben Vorwürfe, dieselbe Situation – wieder und wieder, ohne dass sich etwas verändert oder klärt. Es fühlt sich oft schwer und festgefahren an.</p>
<p>Selbstreflexion dagegen bewegt sich, auch wenn es nur kleine Schritte sind: Sie stellt Fragen, statt Antworten vorwegzunehmen. Sie lässt auch unbequeme Erkenntnisse zu. Und sie führt – im besten Fall – irgendwann zu einem „Ah, deswegen also" oder zu einer kleinen Entscheidung.</p>

<h2>Ein einfacher Prüfstein</h2>
<p>Wenn Sie sich nicht sicher sind, ob Sie gerade reflektieren oder grübeln: Fragen Sie sich, ob der Gedanke Sie gerade weiterbringt oder ob er sich anfühlt wie ein Hamsterrad. Beides ist menschlich und beides passiert jedem – aber die Unterscheidung hilft, bewusster gegenzusteuern, wenn sich ein Gedanke festfährt.</p>

<h2>Was hilft, wenn es ins Grübeln kippt</h2>
<p>Oft hilft es schon, den Gedanken aufzuschreiben, statt ihn nur im Kopf zu wälzen – das allein verändert die Perspektive. Manchmal braucht es aber auch ein Gegenüber, das gezielt nachfragt und neue Blickwinkel eröffnet, wenn man selbst nicht mehr herauskommt.</p>

<p>Genau dafür ist psychologische Beratung da: kein Grübeln in Gesellschaft, sondern ein strukturierter Rahmen für echte Reflexion. Wenn Sie merken, dass Sie mit einem Thema gerade eher im Kreis laufen als voranzukommen, ist ein <a href="/#kontakt">kostenloses Erstgespräch</a> ein guter erster Schritt.</p>
HTML,
            ],
        ];
    }

    public static function bySlug(string $slug): ?array
    {
        foreach (self::all() as $post) {
            if ($post['slug'] === $slug) {
                return $post;
            }
        }
        return null;
    }
}
