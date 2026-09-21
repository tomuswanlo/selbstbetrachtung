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
                'slug' => 'spazieren-statt-netflix-stressabbau',
                'title' => 'Warum ein Spaziergang mehr bringt als Netflix',
                'date' => '2026-09-21',
                'excerpt' => 'Ein aktueller Studien-Review zeigt: Achtsamkeit kombiniert mit Bewegung senkt Stress stärker als reines Ausruhen. Was das für Ihren Alltag bedeutet.',
                'image' => null,
                'image_alt' => null,
                'source_label' => null,
                'source_url' => null,
                'body' => <<<'HTML'
<p>Nach einem anstrengenden Tag auf die Couch fallen und eine Serie schauen – für viele ist das der Inbegriff von Entspannung. Ein aktueller systematischer Review zeichnet ein anderes Bild: Die Kombination aus Achtsamkeit und körperlicher Aktivität senkt das Stresslevel nachweislich stärker als rein passive Methoden wie Fernsehen, Musik hören oder einfaches Nichtstun.</p>

<h2>Was der Review zeigt</h2>
<p>Verglichen wurden aktive Ansätze – Bewegung verbunden mit bewusster Aufmerksamkeit – mit rein passiven Entspannungsformen. Das Ergebnis: Aktive, achtsame Bewegung wirkt sich stärker auf das Stressniveau aus als passives Ausruhen allein. Entscheidend ist dabei laut den Autor:innen weniger die konkrete Methode als vielmehr, wie konsequent sie tatsächlich umgesetzt wird.</p>

<h2>Der Mythos „Entspannung heißt Stillhalten"</h2>
<p>Diese Erkenntnis widerspricht einer weitverbreiteten Annahme: dass Erholung vor allem bedeutet, sich möglichst wenig zu bewegen. Dabei ist unser Nervensystem darauf ausgelegt, Anspannung auch körperlich zu verarbeiten – nicht nur gedanklich. Passives Ablenken kann kurzfristig guttun, verarbeitet die eigentliche Stressreaktion des Körpers aber oft nicht wirklich.</p>

<h2>Warum ein achtsamer Spaziergang so gut funktioniert</h2>
<p>Ein Spaziergang verbindet beides auf einfache Weise: Bewegung und die Möglichkeit, bewusst wahrzunehmen, was gerade um Sie herum und in Ihnen passiert. Das muss nichts Kompliziertes sein – es reicht oft schon, für ein paar Minuten bewusst auf den eigenen Atem und die Schritte zu achten, statt nebenbei aufs Handy zu schauen.</p>

<p>Ein kleines, alltagstaugliches Ritual: fünf Minuten gehen und dabei bewusst atmen. Kein Trainingsplan, kein Zeitdruck – einfach ein kurzer, bewusster Ortswechsel nach der Arbeit, anstatt direkt vor dem Bildschirm zu landen.</p>

<h2>Es kommt auf die Regelmäßigkeit an, nicht auf die Perfektion</h2>
<p>Der vielleicht wichtigste Punkt des Reviews: Nicht die „richtige" Methode entscheidet, sondern wie regelmäßig sie stattfindet. Ein kurzer, immer wiederkehrender achtsamer Spaziergang bringt langfristig mehr als ein aufwendiges Programm, das nach zwei Wochen wieder im Sand verläuft.</p>

<h2>Wo Beratung ansetzen kann</h2>
<p>Häufig ist nicht das fehlende Wissen das Problem, sondern die Umsetzung im Alltag – vor allem an Tagen, an denen genau das schwerfällt. Genau hier kann psychologische Beratung ansetzen: nicht mit einem weiteren starren Plan, sondern dabei, herauszufinden, welches kleine, aktive Ritual tatsächlich zu Ihrem Alltag passt und sich auch in stressigen Phasen durchhalten lässt.</p>

<p>Wenn Sie das Gefühl haben, im eigenen Alltag kaum noch zur Ruhe zu kommen: Ein <a href="/#kontakt">kostenloses, unverbindliches Erstgespräch</a> ist ein guter erster Schritt.</p>

<p><em>Hinweis: Dieser Beitrag beschreibt allgemeine Forschungsergebnisse, keine individuelle medizinische Empfehlung. Bei anhaltenden Stresssymptomen oder gesundheitlichen Beschwerden wenden Sie sich bitte zusätzlich an eine Ärztin, einen Arzt oder eine entsprechende Fachperson.</em></p>
HTML,
            ],
            [
                'slug' => 'sinn-im-leben-kohaerenz-statt-suche',
                'title' => 'Sinn im Leben: Warum Kohärenz mehr zählt als Suche',
                'date' => '2026-09-14',
                'excerpt' => 'Eine große Studie mit über 250.000 Menschen zeigt: Lebenssinn schützt vor Depression – aber aktives „Sinn suchen" kann das Gegenteil bewirken.',
                'image' => null,
                'image_alt' => null,
                'source_label' => null,
                'source_url' => null,
                'body' => <<<'HTML'
<p>„Finde deinen Sinn" gehört zu den meistgehörten Ratschlägen, wenn es um ein erfülltes Leben geht. Eine aktuelle Meta-Analyse zeichnet ein deutlich differenzierteres Bild: Ausgewertet wurden 278 Studien mit über 250.000 Teilnehmenden, veröffentlicht im Journal of Affective Disorders. Das zentrale Ergebnis: Wer Sinn im eigenen Leben empfindet, hat im Schnitt deutlich niedrigere Depressionswerte – am stärksten zeigt sich dieser Zusammenhang bei Menschen mit chronischen Erkrankungen und in der Lebensmitte. Interessant wird es aber bei der zweiten Erkenntnis der Studie, die selten mitgenannt wird.</p>

<h2>Was die Studie zeigt</h2>
<p>Der schützende Effekt gilt für das Vorhandensein von Sinn – nicht für die aktive Suche danach. Menschen, die ihr Leben bereits als sinnvoll erleben, sind seltener von depressiven Symptomen betroffen. Das allein überrascht wenig. Bemerkenswert ist der zweite Teil des Befunds.</p>

<h2>Der Unterschied zwischen „Sinn haben" und „Sinn suchen"</h2>
<p>In individualistisch geprägten Kulturen – wozu auch Deutschland zählt – hängt aktives, anhaltendes Sinn-Suchen sogar mit mehr statt weniger depressiven Symptomen zusammen. Das klingt zunächst widersprüchlich, ergibt aber Sinn, wenn man genauer hinschaut: Wer ständig auf der Suche nach dem „großen Warum" ist, ohne es zu finden, erlebt das eigene Leben oft gerade als nicht stimmig – als offene Frage statt als verstandene Geschichte. Die Suche selbst kann so zur Belastung werden, statt zu entlasten.</p>

<h2>Kohärenz – der eigentliche Schutzfaktor</h2>
<p>Was die Studie als eigentlich schützend identifiziert, ist etwas Konkreteres als „Sinn" im großen, abstrakten Sinne: Kohärenz – das Gefühl, das eigene Leben als verständlich, nachvollziehbar und stimmig zu erleben. Nicht die Antwort auf die Frage „Warum bin ich hier?", sondern die Erfahrung „Meine Geschichte ergibt für mich Sinn, auch mit ihren Brüchen und Umwegen." Das ist ein wichtiger Unterschied: Kohärenz lässt sich erarbeiten, ein kosmisches „Warum" oft nicht.</p>

<h2>Die Brücke zur Langlebigkeitsforschung</h2>
<p>Auch die Longevity-Forschung kennt dieses Thema, etwa im japanischen Konzept „Ikigai" aus den bekannten Blue-Zone-Studien zu besonders langlebigen Regionen der Welt: ein Gefühl von Grund zum Aufstehen am Morgen, eingebettet in Alltag, Beziehungen und Gemeinschaft – weniger eine abstrakte Lebensphilosophie als eine gelebte, alltagsnahe Stimmigkeit. Die hier besprochene Studie selbst untersucht keine Langlebigkeit, aber die Parallele zur Kohärenz-Idee ist auffällig.</p>

<h2>Wo Beratung ansetzen kann</h2>
<p>Genau hier liegt eine sinnvolle Rolle für psychologische Beratung: nicht darin, Ihnen „den Sinn" zu liefern oder eine fertige Antwort auf die großen Fragen zu geben – das wäre weder redlich noch hilfreich. Sondern darin, gemeinsam an der eigenen Kohärenz zu arbeiten: die eigene Lebensgeschichte, auch mit ihren Umbrüchen, wieder verständlicher und stimmiger werden zu lassen. Das ist ein konkreterer, erreichbarerer Prozess als die Suche nach einem großen „Warum".</p>

<p>Sinn im Leben zu empfinden schützt nachweislich vor depressiven Symptomen – aber die ständige, unerfüllte Suche danach kann selbst zur Last werden. Was tatsächlich hilft, ist oft kleinteiliger und erreichbarer, als es klingt: die eigene Geschichte kohärenter zu verstehen. Wenn Sie das Gefühl haben, dass Ihre eigene Geschichte gerade eher zerfasert als stimmig wirkt: Ein <a href="/#kontakt">kostenloses, unverbindliches Erstgespräch</a> ist ein guter erster Schritt.</p>

<p><em>Hinweis: Dieser Beitrag beschreibt statistische Zusammenhänge aus der Forschung, keine individuelle Diagnose. Psychologische Beratung ersetzt keine Psychotherapie – bei anhaltenden depressiven Symptomen wenden Sie sich bitte zusätzlich an eine Ärztin, einen Arzt oder eine Psychotherapeutin bzw. einen Psychotherapeuten.</em></p>
HTML,
            ],
            [
                'slug' => 'stress-zellalterung-was-sie-tun-koennen',
                'title' => 'Stress und Zellalterung: Was Sie beeinflussen können',
                'date' => '2026-09-09',
                'excerpt' => 'Nobelpreis-Forschung zeigt einen Zusammenhang zwischen chronischem Stress und schnellerer Zellalterung. Die gute Nachricht: Es gibt gut belegte Wege, gegenzusteuern.',
                'image' => null,
                'image_alt' => null,
                'source_label' => null,
                'source_url' => null,
                'body' => <<<'HTML'
<p>Was hat Stress mit der Alterung unserer Zellen zu tun? Mehr, als man zunächst denkt. Die Molekularbiologin Elizabeth Blackburn erhielt für ihre Forschung zu Telomeren – den Schutzkappen an den Enden unserer Chromosomen – den Nobelpreis. Ihre späteren Arbeiten zeigen einen Zusammenhang zwischen chronischem, dauerhaftem Stress und einer beschleunigten Verkürzung dieser Telomere. Das klingt zunächst beunruhigend. Die eigentlich interessante Botschaft dahinter ist aber eine andere: Es gibt gut belegte Wege, aktiv gegenzusteuern.</p>

<h2>Der Zusammenhang zwischen Stress und Zellalterung</h2>
<p>Wichtig vorab: Diese Forschung zeigt einen statistischen Zusammenhang, keinen einfachen Automatismus – nicht jeder gestresste Mensch altert dadurch messbar schneller, und Telomerlänge ist nur einer von vielen Faktoren biologischer Alterung. Trotzdem ist der Befund bemerkenswert: Chronischer, unbewältigter Stress wirkt sich nachweislich auf verschiedene Körpersysteme aus – auf das Herz-Kreislauf-System, den Hormonhaushalt und eben auch auf zellulärer Ebene. Es ist also kein rein „gefühlter" Effekt, sondern einer, der sich biologisch nachvollziehen lässt.</p>

<h2>Beziehungen als Schutzfaktor</h2>
<p>Eine der am längsten laufenden Studien zum Thema, die Harvard Study of Adult Development, begleitet Teilnehmende seit den 1930er-Jahren. Ihr zentrales Ergebnis nach fast einem Jahrhundert Forschung: Nicht Geld, Erfolg oder Ruhm sagen am zuverlässigsten voraus, wie gesund und zufrieden Menschen im Alter sind – sondern die Qualität ihrer engsten Beziehungen. Wer sich eingebunden, verstanden und gebraucht fühlt, ist nachweislich widerstandsfähiger gegenüber den körperlichen Folgen von Stress.</p>

<h2>Drei Hebel, die sich tatsächlich beeinflussen lassen</h2>
<p>Aus beiden Forschungslinien zusammengenommen lassen sich drei konkrete Ansatzpunkte für den Alltag ableiten:</p>
<p><strong>1. Achtsamkeit und eine optimistische Grundhaltung trainieren.</strong> Nicht im Sinne von „positiv denken" um jeden Preis, sondern als geübte Fähigkeit, Stresssituationen bewusster wahrzunehmen und einzuordnen, statt ihnen ausgeliefert zu sein.</p>
<p><strong>2. Beziehungen aktiv pflegen.</strong> Nähe entsteht selten von allein – sie braucht regelmäßige, bewusste Aufmerksamkeit. Das betrifft enge Partnerschaften ebenso wie Freundschaften und familiäre Bindungen.</p>
<p><strong>3. Sich als gebraucht und eingebunden erleben.</strong> Ein Gefühl von Sinn und Zugehörigkeit – sei es durch die Familie, den Beruf, ehrenamtliches Engagement oder eine Gemeinschaft – wirkt nachweislich stresspuffernd.</p>

<h2>Wo Beratung ansetzen kann</h2>
<p>An allen drei Punkten lässt sich gezielt arbeiten – nicht, indem man Ihnen sagt, was Sie zu tun haben, sondern indem gemeinsam herausgearbeitet wird, was in Ihrem konkreten Leben gerade fehlt oder im Weg steht. Psychologische Beratung ersetzt dabei keine ärztliche Behandlung und trifft keine medizinischen Aussagen zu Ihrer Gesundheit – sie kann aber ein guter Rahmen sein, um an der eigenen Stressbewältigung, an Beziehungsmustern oder am Gefühl von Zugehörigkeit zu arbeiten.</p>

<p>Stress hinterlässt messbare Spuren – aber Sie sind ihm nicht hilflos ausgeliefert. Achtsamkeit, gepflegte Beziehungen und das Gefühl, gebraucht zu werden, sind drei gut erforschte Hebel, die jeder Mensch zu einem gewissen Grad selbst in der Hand hat. Wenn Sie das Gefühl haben, bei einem dieser Punkte nicht weiterzukommen: Ein <a href="/#kontakt">kostenloses, unverbindliches Erstgespräch</a> ist ein guter erster Schritt.</p>

<p><em>Hinweis: Dieser Beitrag ersetzt keine ärztliche oder psychotherapeutische Beratung. Bei gesundheitlichen Beschwerden wenden Sie sich bitte an eine Ärztin, einen Arzt oder eine entsprechende Fachperson.</em></p>
HTML,
            ],
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
