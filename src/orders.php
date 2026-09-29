<?php
    require_once "partials/session_check.php";

    if (!($_SESSION['mag_orders'] ?? false)) {
        header("Location: vooraad_beheer.php");
        exit();
    }

    $conn = require_once "partials/dbconnection.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'maak_order') {
    $locatie = trim($_POST['locatie'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $stukIds = array_values(array_unique(array_filter(array_map('intval', $_POST['stukken'] ?? []))));

    if ($locatie !== '' && $email !== '' && $status !== '' && $stukIds) {
        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO bestellingen (locatie, email, status) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $locatie, $email, $status);
        $gelukt = $stmt->execute();
        $orderId = $conn->insert_id;
        $stmt->close();

        if ($gelukt) {
            $stukStmt = $conn->prepare("UPDATE voorraad SET bestelling_ID = ?, status = 'besteld' WHERE id = ? AND bestelling_ID = 0 AND status != 'besteld'");
            foreach ($stukIds as $stukId) {
                $stukStmt->bind_param("ii", $orderId, $stukId);
                $stukStmt->execute();
                if ($stukStmt->affected_rows !== 1) {
                    $gelukt = false;
                    break;
                }
            }
            $stukStmt->close();
        }

        if ($gelukt) {
            $conn->commit();
        } else {
            $conn->rollback();
        }

        if (!$gelukt) {
            header("Location: orders.php?fout=aanmaken");
            exit();
        }

        header("Location: orders.php?aangemaakt=1");
        exit();
    }

    header("Location: orders.php?fout=aanmaken");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'wijzig_order') {
    $id = (int) ($_POST['id'] ?? 0);
    $locatie = trim($_POST['locatie'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $verstuurdatum = trim($_POST['verstuurdatum'] ?? '') ?: null;

    if ($id > 0 && $locatie !== '' && $email !== '' && $status !== '') {
        $stmt = $conn->prepare("UPDATE bestellingen SET locatie = ?, email = ?, status = ?, verstuurdatum = ? WHERE ID = ?");
        $stmt->bind_param("ssssi", $locatie, $email, $status, $verstuurdatum, $id);
        $stmt->execute();
        $stmt->close();
        header("Location: orders.php?gewijzigd=1");
        exit();
    }

    header("Location: orders.php?fout=wijziging");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verwijder_order') {
    $id = (int) $_POST['id'];

    // Free the leather pieces first, otherwise they stay "besteld" forever
    $stmt = $conn->prepare("UPDATE voorraad SET bestelling_ID = 0, status = 'beschikbaar' WHERE bestelling_ID = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    $stmt = $conn->prepare("DELETE FROM bestellingen WHERE ID = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    header("Location: orders.php");
    exit();
}

    $bestellingenStmt = $conn->prepare("SELECT ID, locatie, email, status, besteldatum, verstuurdatum FROM bestellingen ORDER BY besteldatum DESC");
    $bestellingenStmt->execute();
    $bestellingen = $bestellingenStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $bestellingenStmt->close();

    $beschikbareStukkenStmt = $conn->prepare("SELECT id, leertype, kleur, dikteMM, lengteCM, breedteCM, prijs FROM voorraad WHERE bestelling_ID = 0 AND status != 'besteld' ORDER BY id DESC");
    $beschikbareStukkenStmt->execute();
    $beschikbareStukken = $beschikbareStukkenStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $beschikbareStukkenStmt->close();

    // Let op: hier GEEN status != 'besteld'-filter op voorraad, zoals op vooraad_beheer.php.
    // De items van een bestelling moeten juist wel zichtbaar zijn in de bestelling zelf.
    $itemsStmt = $conn->prepare("
SELECT v.bestelling_ID AS bestellingen_ID, 1 AS aantal, v.prijs,
           v.leertype, v.kleur, v.dikteMM, v.lengteCM, v.breedteCM
        FROM voorraad v
     WHERE v.bestelling_ID != 0
    ORDER BY v.bestelling_ID
    ");
    $itemsStmt->execute();
    $alleItems = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $itemsStmt->close();
    $conn->close();

    // Items groeperen per bestelling, zodat we ze straks per bestelling kunnen tonen
    $itemsPerBestelling = [];
    foreach ($alleItems as $item) {
        $itemsPerBestelling[$item['bestellingen_ID']][] = $item; 
    }
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bestellingen - Circuleather</title>
    <link rel="stylesheet" type="text/css" href="css/style.css">
</head>
<body class="subpage">
    <div class="pagina-wrapper">
        <a class="terug-link" href="vooraad_beheer.php">&laquo; Terug naar voorraad</a>

        <div class="pagina-kaart invoer-kaart">
            <h1>Bestellingen</h1>
            <details class="nieuwe-order">
                <summary>Nieuwe bestelling maken</summary>
                <form method="POST">
                    <input type="hidden" name="action" value="maak_order">
                    <label>Locatie
                        <input type="text" name="locatie" required>
                    </label>
                    <label>Email
                        <input type="email" name="email" required>
                    </label>
                    <label>Status
                        <input type="text" name="status" value="in behandeling" required>
                    </label>
                    <fieldset>
                        <legend>Leer selecteren</legend>
                        <?php if (empty($beschikbareStukken)): ?>
                            <p class="geen-data">Geen beschikbare leerstukken.</p>
                        <?php else: ?>
                            <?php foreach ($beschikbareStukken as $stuk): ?>
                                <label>
                                    <input type="checkbox" name="stukken[]" value="<?php echo (int) $stuk['id']; ?>">
                                    #<?php echo (int) $stuk['id']; ?>
                                    <?php echo htmlspecialchars($stuk['leertype']); ?>,
                                    <?php echo htmlspecialchars($stuk['kleur']); ?>,
                                    <?php echo htmlspecialchars($stuk['dikteMM']); ?> mm,
                                    <?php echo htmlspecialchars($stuk['lengteCM']); ?> x <?php echo htmlspecialchars($stuk['breedteCM']); ?> cm,
                                    &euro;<?php echo number_format((float) $stuk['prijs'], 2); ?>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </fieldset>
                    <button type="submit">Bestelling maken</button>
                </form>
            </details>
            <?php if (isset($_GET['aangemaakt'])): ?>
                <div class="melding melding-succes">Bestelling aangemaakt.</div>
            <?php elseif (isset($_GET['gewijzigd'])): ?>
                <div class="melding melding-succes">Bestelling bijgewerkt.</div>
            <?php elseif (isset($_GET['fout'])): ?>
                <div class="melding melding-fout">Vul alle verplichte velden in.</div>
            <?php endif; ?>
            <?php if (empty($bestellingen)): ?>
                <p class="geen-data">Er zijn nog geen bestellingen.</p>
            <?php endif; ?>
        </div>

        <?php foreach ($bestellingen as $bestelling): ?>
            <?php
                $items = $itemsPerBestelling[$bestelling['ID']] ?? [];
                $totaal = 0;
                foreach ($items as $item) {
                    $totaal += $item['aantal'] * $item['prijs'];
                }
            ?>
            <div class="pagina-kaart order-kaart">
                <div class="order-kop">
                    <h2>Bestelling #<?php echo (int) $bestelling['ID']; ?> &mdash; <?php echo htmlspecialchars($bestelling['locatie']); ?></h2>
                    <span class="order-status"><?php echo htmlspecialchars($bestelling['status']); ?></span>
                </div>
                <div class="order-meta">
                    <span>Email: <?php echo htmlspecialchars($bestelling['email']); ?></span>
                    <span>Besteld: <?php echo htmlspecialchars($bestelling['besteldatum']); ?></span>
                    <span>Verstuurd: <?php echo $bestelling['verstuurdatum'] ? htmlspecialchars($bestelling['verstuurdatum']) : '-'; ?></span>
                </div>
                <form method="POST" onsubmit="return confirm('Weet je het zeker?');">
                    <input type="hidden" name="action" value="verwijder_order">
                    <input type="hidden" name="id" value="<?php echo (int) $bestelling['ID']; ?>">
                    <button type="submit">Verwijderen</button>
                </form>
                <details>
                    <summary>Bestelling wijzigen</summary>
                    <form method="POST">
                        <input type="hidden" name="action" value="wijzig_order">
                        <input type="hidden" name="id" value="<?php echo (int) $bestelling['ID']; ?>">
                        <label>Locatie
                            <input type="text" name="locatie" value="<?php echo htmlspecialchars($bestelling['locatie'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </label>
                        <label>Email
                            <input type="email" name="email" value="<?php echo htmlspecialchars($bestelling['email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </label>
                        <label>Status
                            <input type="text" name="status" value="<?php echo htmlspecialchars($bestelling['status'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </label>
                        <label>Verstuurdatum
                            <input type="date" name="verstuurdatum" value="<?php echo htmlspecialchars($bestelling['verstuurdatum'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </label>
                        <button type="submit">Wijzigingen opslaan</button>
                    </form>
                </details>

                <?php if (empty($items)): ?>
                    <p class="geen-data">Geen items in deze bestelling.</p>
                <?php else: ?>
                    <div class="tabel-scroll">
                        <table class="order-items">
                            <thead>
                                <tr>
                                    <th>Leertype</th>
                                    <th>Maat</th>
                                    <th>Dikte</th>
                                    <th>Kleur</th>
                                    <th>Aantal</th>
                                    <th>Prijs</th>
                                    <th>Subtotaal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['leertype']); ?></td>
                                        <td><?php echo htmlspecialchars($item['lengteCM']); ?> x <?php echo htmlspecialchars($item['breedteCM']); ?> cm</td>
                                        <td><?php echo htmlspecialchars($item['dikteMM']); ?> mm</td>
                                        <td><?php echo htmlspecialchars($item['kleur']); ?></td>
                                        <td><?php echo (int) $item['aantal']; ?></td>
                                        <td>&euro;<?php echo number_format((float) $item['prijs'], 2); ?></td>
                                        <td>&euro;<?php echo number_format($item['aantal'] * $item['prijs'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="6">Totaal</td>
                                    <td>&euro;<?php echo number_format($totaal, 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <script>
        document.querySelectorAll('form').forEach(function (form) {
            if (!form.querySelector('input[name="stukken[]"]')) {
                return;
            }

            form.addEventListener('submit', function (event) {
                var stukken = form.querySelectorAll('input[name="stukken[]"]:checked');
                if (stukken.length === 0) {
                    event.preventDefault();
                    alert('Selecteer minimaal één stuk leer.');
                }
            });
        });
    </script>
</body>
</html>
