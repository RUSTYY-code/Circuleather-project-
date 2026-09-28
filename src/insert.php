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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                $itemStmt = $conn->prepare("INSERT INTO ontvangst_items (ontvangst_id, voorraad_id, gewichtG, bruikbaarheid) VALUES (?, ?, ?, ?)");

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
    </div>
</body>
</html>
