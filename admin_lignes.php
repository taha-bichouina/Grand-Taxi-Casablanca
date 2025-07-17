<?php
$pdo = new PDO("mysql:host=localhost;dbname=taxi_casablanca;charset=utf8mb4", "root", "", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$id = $_GET['edit'] ?? null;
$deleteId = $_GET['delete'] ?? null;
$message = "";

if ($deleteId) {
  $pdo->prepare("DELETE FROM trajets WHERE line_id = ?")->execute([$deleteId]);
  $pdo->prepare("DELETE FROM taxi_line WHERE id = ?")->execute([$deleteId]);
  header("Location: admin_lignes.php");
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['name'] ?? '');
  $color = trim($_POST['color'] ?? '#3388ff');
  $departure = $_POST['departure'] ?? '';
  $arrival = $_POST['arrival'] ?? '';
  $coordsJson = $_POST['coords'] ?? '[]';
  $coords = json_decode($coordsJson, true);

  if (!$name || !$departure || !$arrival || count($coords) < 2) {
    $message = "❌ Veuillez remplir tous les champs et générer/modifier l'itinéraire.";
  } else {
    try {
      $pdo->beginTransaction();

      if ($id) {
        $pdo->prepare("UPDATE taxi_line SET name = ?, color = ? WHERE id = ?")->execute([$name, $color, $id]);
        $pdo->prepare("DELETE FROM trajets WHERE line_id = ?")->execute([$id]);
        $line_id = $id;
      } else {
        $pdo->prepare("INSERT INTO taxi_line (name, color) VALUES (?, ?)")->execute([$name, $color]);
        $line_id = $pdo->lastInsertId();
      }

      $stmt = $pdo->prepare("INSERT INTO trajets (line_id, latitude, longitude, ordre) VALUES (?, ?, ?, ?)");
      foreach ($coords as $i => $pt) {
        $stmt->execute([$line_id, $pt[1], $pt[0], $i]);
      }

      $pdo->commit();
      $message = $id ? "✅ Ligne modifiée avec succès." : "✅ Nouvelle ligne enregistrée.";
      $id = null;
    } catch (Exception $e) {
      $pdo->rollBack();
      $message = "Erreur : " . $e->getMessage();
    }
  }
}

$lines = $pdo->query("SELECT * FROM taxi_line ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$current = $id ? $pdo->prepare("SELECT * FROM taxi_line WHERE id = ?")->execute([$id]) ?: null : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Gestion des lignes de taxi</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet"/>
  <style>
    #map { height: 500px; }
    .waypoint-icon {
      background: #fff;
      border: 2px solid #3388ff;
      border-radius: 50%;
      width: 22px;
      height: 22px;
      text-align: center;
      line-height: 20px;
      font-size: 12px;
      cursor: pointer;
    }
  </style>
</head>
<body class="p-4">
  <h2>🛣️ Gestion des lignes de grands-taxis</h2>
  <?php if($message):?><div class="alert alert-info"><?= $message ?></div><?php endif;?>

  <form method="POST" id="form">
    <div class="row g-3">
      <div class="col-md-6">
        <label>Nom de la ligne</label>
        <input name="name" class="form-control" required value="<?= $current['name'] ?? '' ?>">
      </div>
      <div class="col-md-3">
        <label>Couleur</label>
        <input name="color" type="color" value="<?= $current['color'] ?? '#3388ff' ?>" class="form-control form-control-color">
      </div>
      <div class="col-md-3">
        <label>&nbsp;</label><br>
        <button type="button" id="gen" class="btn btn-success">Générer l'itinéraire</button>
      </div>
    </div>
    <div class="mt-3 mb-3"><div id="map"></div></div>
    <input type="hidden" name="departure" id="departure">
    <input type="hidden" name="arrival" id="arrival">
    <input type="hidden" name="coords" id="coords">
    <button type="submit" class="btn btn-primary">📌 <?= $id ? "Mettre à jour" : "Enregistrer" ?></button>
    <?php if($id):?><a href="admin_lignes.php" class="btn btn-secondary ms-2">↩️ Annuler</a><?php endif;?>
  </form>

  <hr>
  <h4>Lignes existantes</h4>
  <ul class="list-group">
    <?php foreach($lines as $l):?>
      <li class="list-group-item d-flex justify-content-between align-items-center">
        <span><span style="background:<?= $l['color'] ?>;width:15px;height:15px;display:inline-block;margin-right:8px;"></span><?= htmlspecialchars($l['name']) ?></span>
        <span>
          <a href="admin_lignes.php?edit=<?= $l['id'] ?>" class="btn btn-sm btn-warning">Modifier</a>
          <a href="admin_lignes.php?delete=<?= $l['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Supprimer cette ligne ?')">Supprimer</a>
        </span>
      </li>
    <?php endforeach;?>
  </ul>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    const map = L.map('map').setView([33.5731, -7.5898], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

    let depMarker, arrMarker, routeLine;
    let waypointMarkers = [];

    const departureFld = document.getElementById('departure');
    const arrivalFld = document.getElementById('arrival');
    const coordsFld = document.getElementById('coords');

    async function getLocationName(latlng) {
      const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${latlng.lat}&lon=${latlng.lng}`;
      const res = await fetch(url);
      const data = await res.json();
      return data.address?.suburb || data.address?.neighbourhood || data.address?.road || "Localisation inconnue";
    }

    map.on('click', e => {
      if (!depMarker) {
        depMarker = L.marker(e.latlng, { draggable: true }).addTo(map);
        departureFld.value = `${e.latlng.lat},${e.latlng.lng}`;
        getLocationName(e.latlng).then(name => {
          depMarker.bindPopup(`Départ : ${name}`).openPopup();
        });
        depMarker.on('click', () => {
          getLocationName(depMarker.getLatLng()).then(name => {
            depMarker.bindPopup(`Départ : ${name}`).openPopup();
          });
        });
      } else if (!arrMarker) {
        arrMarker = L.marker(e.latlng, { draggable: true }).addTo(map).bindPopup('Arrivée').openPopup();
        arrivalFld.value = `${e.latlng.lat},${e.latlng.lng}`;
      } else {
        const marker = L.marker(e.latlng, {
          draggable: true,
          icon: L.divIcon({ className: 'waypoint-icon', html: '📍' })
        }).addTo(map);

        marker.on('dragend', updateRouteLine);
        marker.on('click', () => {
          map.removeLayer(marker);
          waypointMarkers = waypointMarkers.filter(m => m !== marker);
          updateRouteLine();
        });

        waypointMarkers.push(marker);
        updateRouteLine();
      }
    });

    document.getElementById('gen').onclick = () => {
      if (!depMarker || !arrMarker) return alert("Sélectionnez départ et arrivée sur la carte.");
      updateRouteLine();
    };

    async function updateRouteLine() {
      if (!depMarker || !arrMarker) return;
      const start = depMarker.getLatLng();
      const end = arrMarker.getLat