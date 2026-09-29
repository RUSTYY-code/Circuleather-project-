<?php
    require_once "partials/session_check.php";

    if (!($_SESSION['mag_insert'] ?? false)) {
        header("Location: vooraad_beheer.php");
        exit();
    }

    $conn = require_once "partials/dbconnection.php";
    $melding = '';
    $fout = '';
    $aantalRijen = 5;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['wijzig_stuk', 'verwijder_stuk'], true)) {
        $voorraadId = (int) ($_POST['voorraad_id'] ?? 0);

        $controleStmt = $conn->prepare("SELECT v.bestelling_ID, oi.ontvangst_id FROM voorraad v LEFT JOIN OntvangstItem oi ON oi.voorraad_id = v.id WHERE v.id = ? LIMIT 1");
        $controleStmt->bind_param("i", $voorraadId);
        $controleStmt->execute();
        $stuk = $controleStmt->get_result()->fetch_assoc();
        $controleStmt->close();

        if (!$stuk) {
            header("Location: insert.php?beheer_fout=niet_gevonden");
            exit();
        }
        $ontvangstId = (int) ($stuk['ontvangst_id'] ?? 0);
        if ((int) ($stuk['bestelling_ID'] ?? 0) !== 0) {
            header("Location: insert.php?beheer_fout=besteld");
            exit();
        }

        if ($_POST['action'] === 'verwijder_stuk') {
            try {
                $conn->begin_transaction();

                if ($ontvangstId > 0) {
                    $verwijderItemStmt = $conn->prepare("DELETE FROM OntvangstItem WHERE ontvangst_id = ? AND voorraad_id = ?");
                    $verwijderItemStmt->bind_param("ii", $ontvangstId, $voorraadId);
                    $verwijderItemStmt->execute();
                    $verwijderItemStmt->close();
                }

                $verwijderVoorraadStmt = $conn->prepare("DELETE FROM voorraad WHERE id = ? AND bestelling_ID = 0");
                $verwijderVoorraadStmt->bind_param("i", $voorraadId);
                $verwijderVoorraadStmt->execute();
                if ($verwijderVoorraadStmt->affected_rows !== 1) {
                    throw new RuntimeException('Voorraadstuk kon niet worden verwijderd.');
                }
                $verwijderVoorraadStmt->close();

                if ($ontvangstId > 0) {
                    $aantalStmt = $conn->prepare("SELECT COUNT(*) AS aantal FROM OntvangstItem WHERE ontvangst_id = ?");
                    $aantalStmt->bind_param("i", $ontvangstId);
                    $aantalStmt->execute();
                    $aantalOver = (int) $aantalStmt->get_result()->fetch_assoc()['aantal'];
                    $aantalStmt->close();

                    if ($aantalOver === 0) {
                        $verwijderOntvangstStmt = $conn->prepare("DELETE FROM Ontvangst WHERE ID = ?");
                        $verwijderOntvangstStmt->bind_param("i", $ontvangstId);
                        $verwijderOntvangstStmt->execute();
                        $verwijderOntvangstStmt->close();
                    }
                }

                $conn->commit();
                $terugNaarVoorraad = ($_POST['return_to'] ?? '') === 'voorraad_beheer.php';
                header($terugNaarVoorraad ? "Location: vooraad_beheer.php?verwijderd=1" : "Location: insert.php?verwijderd=1");
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                header("Location: insert.php?beheer_fout=opslaan");
                exit();
            }
        }

        $herkomst = trim($_POST['herkomst'] ?? '');
        $datum = trim($_POST['datum'] ?? '');
        $leertype = trim($_POST['leertype'] ?? '');
        $dikteMM = (int) ($_POST['dikteMM'] ?? 0);
        $lengteCM = (int) ($_POST['lengteCM'] ?? 0);
        $breedteCM = (int) ($_POST['breedteCM'] ?? 0);
        $kleur = trim($_POST['kleur'] ?? '');
        $prijs = (float) ($_POST['prijs'] ?? 0);
        $gewichtG = (float) ($_POST['gewichtG'] ?? 0);
        $bruikbaarheid = (float) ($_POST['bruikbaarheid'] ?? 0);

        if (($ontvangstId > 0 && ($herkomst === '' || $datum === '')) || $leertype === '' || min($dikteMM, $lengteCM, $breedteCM, $prijs, $gewichtG, $bruikbaarheid) < 0) {
            header("Location: insert.php?beheer_fout=gegevens");
            exit();
        }

        try {
            $conn->begin_transaction();

            $voorraadUpdateStmt = $conn->prepare("UPDATE voorraad SET leertype = ?, dikteMM = ?, lengteCM = ?, breedteCM = ?, gewichtG = ?, kleur = ?, prijs = ? WHERE id = ? AND bestelling_ID = 0");
            $voorraadUpdateStmt->bind_param("siiidsdi", $leertype, $dikteMM, $lengteCM, $breedteCM, $gewichtG, $kleur, $prijs, $voorraadId);
            $voorraadUpdateStmt->execute();
            $voorraadUpdateStmt->close();

            if ($ontvangstId > 0) {
                $itemUpdateStmt = $conn->prepare("UPDATE OntvangstItem SET gewichtG = ?, bruikbaarheid = ? WHERE ontvangst_id = ? AND voorraad_id = ?");
                $itemUpdateStmt->bind_param("ddii", $gewichtG, $bruikbaarheid, $ontvangstId, $voorraadId);
                $itemUpdateStmt->execute();
                $itemUpdateStmt->close();

                $ontvangstUpdateStmt = $conn->prepare("UPDATE Ontvangst SET herkomst = ?, datum = ? WHERE ID = ?");
                $ontvangstUpdateStmt->bind_param("ssi", $herkomst, $datum, $ontvangstId);
                $ontvangstUpdateStmt->execute();
                $ontvangstUpdateStmt->close();
            }

            $conn->commit();
            header("Location: insert.php?gewijzigd=1");
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            header("Location: insert.php?beheer_fout=opslaan");
            exit();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
        $herkomst = trim($_POST['herkomst'] ?? '');
        $datum = $_POST['datum'] ?? date('Y-m-d');

        if ($herkomst === '') {
            $fout = 'Vul een herkomst in.';
        } else {
            // Lege rijen (geen leertype ingevuld) worden overgeslagen, dus je hoeft niet
            // alle 5 rijen te gebruiken om meerdere stukken aan 1 ontvangst toe te voegen.
            $rijen = [];
            for ($i = 0; $i < $aantalRijen; $i++) {
                $leertype = trim($_POST['leertype'][$i] ?? '');
                if ($leertype === '') {
                    continue;
                }
                $rijen[] = [
                    'leertype' => $leertype,
                    'dikteMM' => (int) ($_POST['dikteMM'][$i] ?? 0),
                    'lengteCM' => (int) ($_POST['lengteCM'][$i] ?? 0),
                    'breedteCM' => (int) ($_POST['breedteCM'][$i] ?? 0),
                    'kleur' => trim($_POST['kleur'][$i] ?? ''),
                    'prijs' => (float) ($_POST['prijs'][$i] ?? 0),
                    'gewichtG' => (float) ($_POST['gewichtG'][$i] ?? 0),
                    'bruikbaarheid' => (float) ($_POST['bruikbaarheid'][$i] ?? 0),
                ];
            }

            if (empty($rijen)) {
                $fout = 'Vul minimaal 1 stuk leer in (leertype is verplicht per rij).';
            } else {
                $ontvangstStmt = $conn->prepare("INSERT INTO Ontvangst (herkomst, datum) VALUES (?, ?)");
                $ontvangstStmt->bind_param("ss", $herkomst, $datum);
                $ontvangstStmt->execute();
                $ontvangstId = $ontvangstStmt->insert_id;
                $ontvangstStmt->close();

                $voorraadStmt = $conn->prepare("INSERT INTO voorraad (leertype, dikteMM, lengteCM, breedteCM, gewichtG, kleur, prijs) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $itemStmt = $conn->prepare("INSERT INTO OntvangstItem (ontvangst_id, voorraad_id, gewichtG, bruikbaarheid) VALUES (?, ?, ?, ?)");

                foreach ($rijen as $rij) {
                    $voorraadStmt->bind_param(
                        "siiidsd",
                        $rij['leertype'],
                        $rij['dikteMM'],
                        $rij['lengteCM'],
                        $rij['breedteCM'],  
                        $rij['gewichtG'],
                        $rij['kleur'],
                        $rij['prijs']
                    );
                    $voorraadStmt->execute();
                    $voorraadId = $voorraadStmt->insert_id;

                    $itemStmt->bind_param("iidd", $ontvangstId, $voorraadId, $rij['gewichtG'], $rij['bruikbaarheid']);
                    $itemStmt->execute();
                }
                $voorraadStmt->close();
                $itemStmt->close();

                $melding = count($rijen) . ' stuk(s) toegevoegd aan de voorraad via ontvangst #' . $ontvangstId . '.';

                $conn->close();
                header("Location: vooraad_beheer.php?ontvangst=" . $ontvangstId . "&aantal=" . count($rijen));
                exit();
            }
        }
    }

    $ontvangstenStmt = $conn->prepare("SELECT v.id AS voorraad_id, v.bestelling_ID, v.status, v.leertype, v.dikteMM, v.lengteCM, v.breedteCM, v.gewichtG, v.kleur, v.prijs, o.ID AS ontvangst_id, o.herkomst, o.datum, oi.bruikbaarheid FROM voorraad v LEFT JOIN OntvangstItem oi ON oi.voorraad_id = v.id LEFT JOIN Ontvangst o ON o.ID = oi.ontvangst_id ORDER BY v.id DESC");
    $ontvangstenStmt->execute();
    $ontvangstStukken = $ontvangstenStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ontvangstenStmt->close();
    $conn->close();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ontvangst invoeren - Circuleather</title>
    <link rel="stylesheet" type="text/css" href="css/style.css">
</head>
<body class="subpage">
    <div class="pagina-wrapper">
        <a class="terug-link" href="vooraad_beheer.php">&laquo; Terug naar voorraad</a>

        <div class="pagina-kaart invoer-kaart">
            <h1>Ontvangst invoeren</h1>
            <p class="rij-hint">Vul de herkomst/datum van de ontvangst in, en daaronder 1 of meer stukken leer die je hebt ontvangen. Laat een rij leeg (geen leertype) als je 'm niet gebruikt.</p>

            <?php if (isset($_GET['gewijzigd'])): ?>
                <div class="melding melding-succes">Ontvangststuk bijgewerkt.</div>
            <?php elseif (isset($_GET['verwijderd'])): ?>
                <div class="melding melding-succes">Ontvangststuk verwijderd.</div>
            <?php elseif (isset($_GET['beheer_fout'])): ?>
                <div class="melding melding-fout">
                    <?php
                        $beheerFouten = [
                            'niet_gevonden' => 'Dit ontvangststuk bestaat niet meer.',
                            'besteld' => 'Dit stuk is aan een bestelling gekoppeld en kan niet worden gewijzigd of verwijderd.',
                            'gegevens' => 'Controleer de verplichte velden en voer positieve waarden in.',
                            'opslaan' => 'De wijziging kon niet worden opgeslagen.',
                        ];
                        echo htmlspecialchars($beheerFouten[$_GET['beheer_fout']] ?? 'Er is iets misgegaan.');
                    ?>
                </div>
            <?php endif; ?>

            <?php if ($fout !== ''): ?>
                <div class="melding melding-fout"><?php echo htmlspecialchars($fout); ?></div>
            <?php endif; ?>
            <?php if ($melding !== ''): ?>
                <div class="melding melding-succes"><?php echo htmlspecialchars($melding); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="ontvangst-header">
                    <label>Herkomst
                        <input class="form-input" type="text" name="herkomst" required>
                    </label>
                    <label>Datum
                        <input class="form-input" type="date" name="datum" value="<?php echo date('Y-m-d'); ?>">
                    </label>
                </div>

                <div class="tabel-scroll">
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Leertype</th>
                                <th>Dikte (mm)</th>
                                <th>Lengte (cm)</th>
                                <th>Breedte (cm)</th>
                                <th>Kleur</th>
                                <th>Prijs (&euro;)</th>
                                <th>Gewicht (g)</th>
                                <th>Bruikbaarheid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i = 0; $i < $aantalRijen; $i++): ?>
                                <tr>
                                    <td><input type="text" name="leertype[]"></td>
                                    <td><input type="number" name="dikteMM[]" min="0"></td>
                                    <td><input type="number" name="lengteCM[]" min="0"></td>
                                    <td><input type="number" name="breedteCM[]" min="0"></td>
                                    <td><input type="text" name="kleur[]"></td>
                                    <td><input type="number" name="prijs[]" min="0" step="0.01"></td>
                                    <td><input type="number" name="gewichtG[]" min="0" step="0.1"></td>
                                    <td><input type="number" name="bruikbaarheid[]" min="0" step="0.1"></td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>

                <button class="btn-primary" type="submit">Ontvangst opslaan</button>
            </form>
        </div>

        <div class="pagina-kaart invoer-kaart">
            <h2>Alle voorraadstukken</h2>
            <p>Totaal: <?php echo count($ontvangstStukken); ?> stuk(<?php echo count($ontvangstStukken) === 1 ? '' : 's'; ?>)</p>
            <?php if (empty($ontvangstStukken)): ?>
                <p class="geen-data">Er zijn nog geen voorraadstukken.</p>
            <?php else: ?>
                <div class="tabel-scroll">
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Bestelling</th>
                                <th>Herkomst</th>
                                <th>Datum</th>
                                <th>Leertype</th>
                                <th>Dikte</th>
                                <th>Lengte</th>
                                <th>Breedte</th>
                                <th>Gewicht</th>
                                <th>Kleur</th>
                                <th>Prijs</th>
                                <th>Bruikbaarheid</th>
                                <th>Status</th>
                                <th>Beheer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ontvangstStukken as $stuk): ?>
                                <tr>
                                    <td><?php echo (int) $stuk['voorraad_id']; ?></td>
                                    <td><?php echo (int) ($stuk['bestelling_ID'] ?? 0) ?: '-'; ?></td>
                                    <td><?php echo htmlspecialchars($stuk['herkomst'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($stuk['datum'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($stuk['leertype']); ?></td>
                                    <td><?php echo (int) $stuk['dikteMM']; ?> mm</td>
                                    <td><?php echo (int) $stuk['lengteCM']; ?> cm</td>
                                    <td><?php echo (int) $stuk['breedteCM']; ?> cm</td>
                                    <td><?php echo htmlspecialchars((string) $stuk['gewichtG']); ?> g</td>
                                    <td><?php echo htmlspecialchars($stuk['kleur'] ?? ''); ?></td>
                                    <td>&euro;<?php echo number_format((float) $stuk['prijs'], 2); ?></td>
                                    <td><?php echo isset($stuk['bruikbaarheid']) ? htmlspecialchars((string) $stuk['bruikbaarheid']) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars($stuk['status'] ?? '-'); ?></td>
                                    <td>
                                        <?php if ((int) $stuk['bestelling_ID'] === 0): ?>
                                            <details id="voorraad-<?php echo (int) $stuk['voorraad_id']; ?>"<?php echo isset($_GET['bewerken']) && (int) $_GET['bewerken'] === (int) $stuk['voorraad_id'] ? ' open' : ''; ?>>
                                                <summary>Wijzigen</summary>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="wijzig_stuk">
                                                    <input type="hidden" name="voorraad_id" value="<?php echo (int) $stuk['voorraad_id']; ?>">
                                                    <?php if (!empty($stuk['ontvangst_id'])): ?>
                                                        <label>Herkomst
                                                            <input type="text" name="herkomst" value="<?php echo htmlspecialchars($stuk['herkomst'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                                        </label>
                                                        <label>Datum
                                                            <input type="date" name="datum" value="<?php echo htmlspecialchars($stuk['datum'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                                        </label>
                                                    <?php endif; ?>
                                                    <label>Leertype
                                                        <input type="text" name="leertype" value="<?php echo htmlspecialchars($stuk['leertype'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                                    </label>
                                                    <label>Dikte (mm)
                                                        <input type="number" name="dikteMM" min="0" value="<?php echo (int) $stuk['dikteMM']; ?>" required>
                                                    </label>
                                                    <label>Lengte (cm)
                                                        <input type="number" name="lengteCM" min="0" value="<?php echo (int) $stuk['lengteCM']; ?>" required>
                                                    </label>
                                                    <label>Breedte (cm)
                                                        <input type="number" name="breedteCM" min="0" value="<?php echo (int) $stuk['breedteCM']; ?>" required>
                                                    </label>
                                                    <label>Kleur
                                                        <input type="text" name="kleur" value="<?php echo htmlspecialchars($stuk['kleur'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    </label>
                                                    <label>Prijs (&euro;)
                                                        <input type="number" name="prijs" min="0" step="0.01" value="<?php echo htmlspecialchars((string) $stuk['prijs'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                                    </label>
                                                    <label>Gewicht (g)
                                                        <input type="number" name="gewichtG" min="0" step="0.1" value="<?php echo htmlspecialchars((string) $stuk['gewichtG'], ENT_QUOTES, 'UTF-8'); ?>" required>
                                                    </label>
                                                    <?php if (!empty($stuk['ontvangst_id'])): ?>
                                                        <label>Bruikbaarheid
                                                            <input type="number" name="bruikbaarheid" min="0" step="0.1" value="<?php echo htmlspecialchars((string) ($stuk['bruikbaarheid'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" required>
                                                        </label>
                                                    <?php endif; ?>
                                                    <button type="submit">Wijzigingen opslaan</button>
                                                </form>
                                            </details>
                                            <form method="POST" onsubmit="return confirm('Weet je zeker dat je dit ontvangststuk wilt verwijderen?');">
                                                <input type="hidden" name="action" value="verwijder_stuk">
                                                <input type="hidden" name="voorraad_id" value="<?php echo (int) $stuk['voorraad_id']; ?>">
                                                <button type="submit">Verwijderen</button>
                                            </form>
                                        <?php else: ?>
                                            Niet beschikbaar zolang het stuk besteld is.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
