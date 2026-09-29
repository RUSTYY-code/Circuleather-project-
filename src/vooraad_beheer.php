<?php
    require_once "partials/session_check.php";
    $conn = require_once "partials/dbconnection.php";

    // Filters (waardes komen automatisch uit de database)
    $geselecteerdLeertype = trim($_GET['leertype'] ?? '');
    $geselecteerdKleur = trim($_GET['kleur'] ?? '');
    $geselecteerdDikte = trim($_GET['dikte'] ?? '');
    $geselecteerdMaat = trim($_GET['maat'] ?? '');

    // Maat-filter werkt met vaste categorieën i.p.v. losse waardes uit de database
    $maatCategorieen = [
        'A' => [23, 40],
        'B' => [40, 60],
        'C' => [60, null],
    ];

    // Herbruikbare CASE-expressie (prioriteit A -> B -> C, 1 van de 2 maten hoeft maar te passen)
    $maatCaseSql = 'CASE';
    foreach ($maatCategorieen as $categorie => [$min, $max]) {
        if ($max === null) {
            $maatCaseSql .= " WHEN lengteCM >= $min OR breedteCM >= $min THEN '$categorie'";
        } else {
            $maatCaseSql .= " WHEN (lengteCM >= $min AND lengteCM < $max) OR (breedteCM >= $min AND breedteCM < $max) THEN '$categorie'";
        }
    }
    $maatCaseSql .= ' END';

    // Elke conditie apart, zodat we per filter-dropdown de tellingen kunnen baseren op de
    // AL GESELECTEERDE ANDERE filters, zonder de eigen conditie mee te tellen (anders zou
    // je eigen dropdown na selectie overal 0 laten zien behalve bij de gekozen waarde).
    $leertypeConditie = $geselecteerdLeertype !== '' ? ['leertype = ?', [$geselecteerdLeertype], 's'] : null;
    $kleurConditie = $geselecteerdKleur !== '' ? ['kleur = ?', [$geselecteerdKleur], 's'] : null;
    $dikteConditie = $geselecteerdDikte !== '' ? ['dikteMM = ?', [$geselecteerdDikte], 's'] : null;
    $maatConditie = ($geselecteerdMaat !== '' && isset($maatCategorieen[$geselecteerdMaat]))
        ? ["$maatCaseSql = ?", [$geselecteerdMaat], 's']
        : null;

    function bouwWhereClause(array $condities): array {
        $sqlDelen = ["status != 'besteld'"];
        $params = [];
        $types = '';
        foreach ($condities as $conditie) {
            if ($conditie === null) {
                continue;
            }
            [$sql, $condParams, $condTypes] = $conditie;
            $sqlDelen[] = $sql;
            foreach ($condParams as $param) {
                $params[] = $param;
            }
            $types .= $condTypes;
        }
        return [' WHERE ' . implode(' AND ', $sqlDelen), $params, $types];
    }

    // Leertype-tellingen: alle filters BEHALVE leertype zelf
    [$leertypeWhereSql, $leertypeParams, $leertypeTypes] = bouwWhereClause([$kleurConditie, $dikteConditie, $maatConditie]);
    $leertypesStmt = $conn->prepare("SELECT leertype, COUNT(*) AS aantal FROM voorraad" . $leertypeWhereSql . " GROUP BY leertype ORDER BY leertype");
    if ($leertypeParams) {
        $leertypesStmt->bind_param($leertypeTypes, ...$leertypeParams);
    }
    $leertypesStmt->execute();
    $leertypes = $leertypesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $leertypesStmt->close();

    // Kleur-tellingen: alle filters BEHALVE kleur zelf
    [$kleurWhereSql, $kleurParams, $kleurTypes] = bouwWhereClause([$leertypeConditie, $dikteConditie, $maatConditie]);
    $kleurenStmt = $conn->prepare("SELECT kleur, COUNT(*) AS aantal FROM voorraad" . $kleurWhereSql . " GROUP BY kleur ORDER BY kleur");
    if ($kleurParams) {
        $kleurenStmt->bind_param($kleurTypes, ...$kleurParams);
    }
    $kleurenStmt->execute();
    $kleuren = $kleurenStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $kleurenStmt->close();

    // Dikte-tellingen: alle filters BEHALVE dikte zelf
    [$dikteWhereSql, $dikteParams, $dikteTypes] = bouwWhereClause([$leertypeConditie, $kleurConditie, $maatConditie]);
    $diktesStmt = $conn->prepare("SELECT dikteMM, COUNT(*) AS aantal FROM voorraad" . $dikteWhereSql . " GROUP BY dikteMM ORDER BY dikteMM");
    if ($dikteParams) {
        $diktesStmt->bind_param($dikteTypes, ...$dikteParams);
    }
    $diktesStmt->execute();
    $diktes = $diktesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $diktesStmt->close();

    // Maat-tellingen: alle filters BEHALVE maat zelf
    [$maatWhereSql, $maatParams, $maatTypes] = bouwWhereClause([$leertypeConditie, $kleurConditie, $dikteConditie]);
    $maatCountsStmt = $conn->prepare("SELECT $maatCaseSql AS categorie, COUNT(*) AS aantal FROM voorraad" . $maatWhereSql . " GROUP BY categorie");
    if ($maatParams) {
        $maatCountsStmt->bind_param($maatTypes, ...$maatParams);
    }
    $maatCountsStmt->execute();
    $maatCounts = [];
    foreach ($maatCountsStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $rij) {
        $maatCounts[$rij['categorie']] = $rij['aantal'];
    }
    $maatCountsStmt->close();

    // Hoofd-query: ALLE filters samen
    [$whereSql, $params, $types] = bouwWhereClause([$leertypeConditie, $kleurConditie, $dikteConditie, $maatConditie]);

    // filterQuery zorgt dat de paginering-links de actieve filters onthouden
    $filterQuery = '';
    if ($geselecteerdLeertype !== '') {
        $filterQuery .= '&leertype=' . urlencode($geselecteerdLeertype);
    }
    if ($geselecteerdKleur !== '') {
        $filterQuery .= '&kleur=' . urlencode($geselecteerdKleur);
    }
    if ($geselecteerdDikte !== '') {
        $filterQuery .= '&dikte=' . urlencode($geselecteerdDikte);
    }
    if ($geselecteerdMaat !== '') {
        $filterQuery .= '&maat=' . urlencode($geselecteerdMaat);
    }

    // Paginering
    $itemsPerPagina = 12;
    $huidigePagina = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
    $offset = ($huidigePagina - 1) * $itemsPerPagina;

    $totaalStmt = $conn->prepare("SELECT COUNT(*) AS totaal FROM voorraad" . $whereSql);
    if ($params) {
        $totaalStmt->bind_param($types, ...$params);
    }
    $totaalStmt->execute();
    $totaalRij = $totaalStmt->get_result()->fetch_assoc();
    $totaalPaginas = max(1, (int) ceil($totaalRij['totaal'] / $itemsPerPagina));
    $totaalStmt->close();

    // Toon max 5 paginaknoppen, gecentreerd rond de huidige pagina
    $maxPaginaKnoppen = 5;
    $vanafPagina = max(1, $huidigePagina - intdiv($maxPaginaKnoppen, 2));
    $totPagina = min($totaalPaginas, $vanafPagina + $maxPaginaKnoppen - 1);
    $vanafPagina = max(1, $totPagina - $maxPaginaKnoppen + 1);

    $selectParams = $params;
    $selectParams[] = $itemsPerPagina;
    $selectParams[] = $offset;

    $stmt = $conn->prepare("SELECT id, leertype, dikteMM, lengteCM, breedteCM, gewichtG, kleur, prijs FROM voorraad" . $whereSql . " LIMIT ? OFFSET ?");
    $stmt->bind_param($types . "ii", ...$selectParams);
    $stmt->execute();
    $producten = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
?>
<!DOCTYPE html>
    <html lang="nl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Voorraad beheer</title>
        <link rel="stylesheet" type="text/css" href="css/style.css">
    </head>
    <body>

        <header class="header">
            <div class="titel"><img src="png/titel.png" alt="titel"></div>
            <div class="inNout-wrapper">
                <div class="inNout">
                    <?php if ($_SESSION['mag_insert'] ?? false): ?>
                        <div class="inNoutB"><a href="insert.php"><button>insert</button></a></div>
                    <?php endif; ?>
                    <?php if ($_SESSION['mag_wijzigen'] ?? false): ?>
                       <div class="inNoutB"><a href="change.php"><button>wijzigen</button></a></div>
                    <?php endif; ?>
                    <?php if ($_SESSION['mag_orders'] ?? false): ?>
                        <div class="inNoutB"><a href="orders.php"><button>orders</button></a></div>
                    <?php endif; ?>
                </div>
                <div class="inNout-user">
                    <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    <a href="logout.php">Uitloggen</a>
                </div>
            </div>
        </header>

        <?php if (isset($_GET['ontvangst'])): ?>
            <div class="melding-banner">Ontvangst #<?php echo (int) $_GET['ontvangst']; ?> opgeslagen — <?php echo (int) ($_GET['aantal'] ?? 0); ?> stuk(s) toegevoegd aan de voorraad.</div>
        <?php endif; ?>
        <?php if (isset($_GET['verwijderd'])): ?>
            <div class="melding-banner">Voorraadstuk verwijderd.</div>
        <?php endif; ?>

        <div class="main-container">

            <aside class="sidebar">
                <form class="filter-opties" method="get">
                    <p>Filter opties</p>
                    <div class="filter-selects">
                    <select class="filter-select" name="leertype" onchange="this.form.submit()">
                        <option value="">Leertypes</option>
                        <?php foreach ($leertypes as $rij): ?>
                            <option value="<?php echo htmlspecialchars($rij['leertype']); ?>"<?php echo $rij['leertype'] === $geselecteerdLeertype ? ' selected' : ''; ?>><?php echo htmlspecialchars($rij['leertype']); ?>(<?php echo $rij['aantal']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <select class="filter-select" name="kleur" onchange="this.form.submit()">
                        <option value="">Kleuren</option>
                        <?php foreach ($kleuren as $rij): ?>
                            <option value="<?php echo htmlspecialchars($rij['kleur']); ?>"<?php echo $rij['kleur'] === $geselecteerdKleur ? ' selected' : ''; ?>><?php echo htmlspecialchars($rij['kleur']); ?> (<?php echo $rij['aantal']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <select class="filter-select" name="dikte" onchange="this.form.submit()">
                        <option value="">Diktes</option>
                        <?php foreach ($diktes as $rij): ?>
                            <option value="<?php echo htmlspecialchars($rij['dikteMM']); ?>"<?php echo $rij['dikteMM'] == $geselecteerdDikte && $geselecteerdDikte !== '' ? ' selected' : ''; ?>><?php echo htmlspecialchars($rij['dikteMM']); ?> mm (<?php echo $rij['aantal']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <select class="filter-select" name="maat" onchange="this.form.submit()">
                        <option value="">Maten</option>
                        <option value="A"<?php echo $geselecteerdMaat === 'A' ? ' selected' : ''; ?>>(23-40 cm) (<?php echo $maatCounts['A'] ?? 0; ?>)</option>
                        <option value="B"<?php echo $geselecteerdMaat === 'B' ? ' selected' : ''; ?>>(40-60 cm) (<?php echo $maatCounts['B'] ?? 0; ?>)</option>
                        <option value="C"<?php echo $geselecteerdMaat === 'C' ? ' selected' : ''; ?>>(60+ cm) (<?php echo $maatCounts['C'] ?? 0; ?>)</option>
                    </select>
                    </div>
                    <a class="reset-filters" href="vooraad_beheer.php">Reset filters</a>
                </form>
                <nav class="pagination">
                    <div class="page-numbers">
                        <?php for ($i = $vanafPagina; $i <= $totPagina; $i++): ?>
                            <a class="page-btn<?php echo $i === $huidigePagina ? ' active' : ''; ?>" href="?pagina=<?php echo $i; ?><?php echo $filterQuery; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                    <div class="page-nav">
                        <a class="page-btn<?php echo $huidigePagina <= 1 ? ' disabled' : ''; ?>" href="?pagina=<?php echo max(1, $huidigePagina - 1); ?><?php echo $filterQuery; ?>">&laquo;</a>
                        <a class="page-btn<?php echo $huidigePagina >= $totaalPaginas ? ' disabled' : ''; ?>" href="?pagina=<?php echo min($totaalPaginas, $huidigePagina + 1); ?><?php echo $filterQuery; ?>">&raquo;</a>
                    </div>
                </nav>
            </aside>

            <main class="content">
                <div class="product-grid">
                    <?php foreach ($producten as $product): ?>
                        <div class="product-card">
                            <div class="naam-box"><span>leertype</span><span><?php echo htmlspecialchars($product['leertype']); ?></span></div>
                            <div class="specs">
                                <div class="spec-row"><span>Dikte</span><span><?php echo htmlspecialchars($product['dikteMM']); ?> mm</span></div>
                                <div class="spec-row"><span>Maat</span><span><?php echo htmlspecialchars($product['lengteCM']); ?> x <?php echo htmlspecialchars($product['breedteCM']); ?> cm</span></div>
                                <div class="spec-row"><span>Gewicht</span><span><?php echo htmlspecialchars($product['gewichtG']); ?> g</span></div>
                                <div class="spec-row"><span>Kleur</span><span><?php echo htmlspecialchars($product['kleur']); ?></span></div>
                            </div>
                            <div class="hoeveelheid-box">&euro;<?php echo htmlspecialchars(number_format((float) $product['prijs'], 2)); ?> p/kg</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </main>

        </div>
    </body>
</html>