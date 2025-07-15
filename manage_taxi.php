<?php
session_start();
$pdo = new PDO("mysql:host=localhost;dbname=taxi_casablanca;charset=utf8mb4", "root", "", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
if (!isset($_SESSION['user'])) {
  $login_required = true;
} else {
  $login_required = false;
}

$message = "";
$editId = $_GET['edit'] ?? null;
$deleteId = $_GET['delete'] ?? null;

// 🔐 LOGIN
if (isset($_POST['login_email']) && isset($_POST['login_password'])) {
  $email = $_POST['login_email'];
  $password = $_POST['login_password'];

  $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
  $stmt->execute([$email]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($user && password_verify($password, $user['password'])) {
    if ($user['status'] === 'validé') {
      $_SESSION['user'] = $user['email'];
      header("Location: manage_taxi.php");
      exit;
    } else {
      $message = "⛔ Compte non validé.";
    }
  } else {
    $message = "❌ Identifiants incorrects.";
  }
}

// 📝 REGISTER
if (isset($_POST['register_email']) && isset($_POST['register_password'])) {
  $email = $_POST['register_email'];
  $password = password_hash($_POST['register_password'], PASSWORD_DEFAULT);

  try {
    $pdo->prepare("INSERT INTO users (email, password) VALUES (?, ?)")->execute([$email, $password]);
    $message = "✅ Inscription réussie. En attente de validation.";
  } catch (Exception $e) {
    $message = "Erreur d'inscription : " . $e->getMessage();
  }
}

// 🗑️ SUPPRESSION
if ($deleteId) {
  $pdo->prepare("DELETE FROM trajets WHERE line_id = ?")->execute([$deleteId]);
  $pdo->prepare("DELETE FROM taxi_line WHERE id = ?")->execute([$deleteId]);
  header("Location: manage_taxi.php");
  exit;
}

// 🚖 ENREGISTREMENT / MODIFICATION DE LIGNE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
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
      if ($editId) {
        $pdo->prepare("UPDATE taxi_line SET name = ?, color = ? WHERE id = ?")->execute([$name, $color, $editId]);
        $pdo->prepare("DELETE FROM trajets WHERE line_id = ?")->execute([$editId]);
        $line_id = $editId;
      } else {
        $pdo->prepare("INSERT INTO taxi_line (name, color) VALUES (?, ?)")->execute([$name, $color]);
        $line_id = $pdo->lastInsertId();
      }

      $stmt = $pdo->prepare("INSERT INTO trajets (line_id, latitude, longitude, ordre) VALUES (?, ?, ?, ?)");
      foreach ($coords as $i => $pt) {
        $stmt->execute([$line_id, $pt[1], $pt[0], $i]);
      }

      $pdo->commit();
      header("Location: manage_taxi.php");
      exit;
    } catch (Exception $e) {
      $pdo->rollBack();
      $message = "Erreur : " . $e->getMessage();
    }
  }
}

// 🔄 RÉCUPÉRATION DES LIGNES EXISTANTES
$lines = $pdo->query("SELECT * FROM taxi_line ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$current = null;
$points = [];

if ($editId) {
  $stmt = $pdo->prepare("SELECT * FROM taxi_line WHERE id = ?");
  $stmt->execute([$editId]);
  $current = $stmt->fetch(PDO::FETCH_ASSOC);

  $stmt = $pdo->prepare("SELECT latitude, longitude FROM trajets WHERE line_id = ? ORDER BY ordre ASC");
  $stmt->execute([$editId]);
  $points = $stmt->fetchAll(PDO::FETCH_NUM);
}
?>

<!DOCTYPE html>
<html lang="fr" data-bs-theme="dark">
    <?php if ($login_required): ?>
  <!-- Ne pas afficher le formulaire taxi tant que l'utilisateur n'est pas connecté -->
<?php else: ?>
  <!-- Afficher le formulaire taxi + carte -->
<?php endif; ?>
<head>
  <meta charset="UTF-8">
  <title>Dashboard Taxi</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap & Leaflet -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">

  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">

  <style>
    body {
      font-family: 'Poppins', sans-serif;
      background-color: #0c1a2b;
      color: #f1f5f9;
    }
    #map { height: 500px; border: 1px solid #1f2d41; }
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
    .modal-content {
      background-color: #0c1a2b;
      border-radius: 12px;
    }
    .form-control, .form-control:focus {
      background-color: #162740;
      border-color: #29497c;
      color: #f1f5f9;
    }
    .form-control::placeholder {
      color: #96a5b8;
    }
    .btn-primary {
      background-color: #3388ff;
      border: none;
    }
    .btn-primary:hover {
      background-color: #1d70d1;
    }
  </style>
</head>
<body class="p-4">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h2>🚕 Gestion des lignes taxi</h2>
      <?php if (!isset($_SESSION['user'])): ?>
      <button class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#authModal">Se connecter</button>
      <?php else: ?>
      <span class="text-success">Connecté : <?= htmlspecialchars($_SESSION['user']) ?></span>
      <a href="logout.php" class="btn btn-sm btn-danger ms-3">Déconnexion</a>
      <?php endif; ?>
    </div>

    <?php if($message):?><div class="alert alert-info"><?= $message ?></div><?php endif;?>

    <!-- 🔐 Fenêtre modale login/register -->
    <div class="modal fade" id="authModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content p-4">
          <h5 class="modal-title text-center mb-3">Connexion / Inscription</h5>
          <ul class="nav nav-tabs" id="authTab" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login" type="button">Connexion</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" id="register-tab" data-bs-toggle="tab" data-bs-target="#register" type="button">Inscription</button>
            </li>
          </ul>
          <div class="tab-content mt-3" id="authTabContent">
            <!-- Login -->
            <div class="tab-pane fade show active" id="login" role="tabpanel">
              <form method="POST">
                <input name="login_email" class="form-control mb-3" type="email" placeholder="Email" required>
                <input name="login_password" class="form-control mb-3" type="password" placeholder="Mot de passe" required>
                <button type="submit" class="btn btn-primary w-100">Connexion</button>
              </form>
            </div>
            <!-- Register -->
            <div class="tab-pane fade" id="register" role="tabpanel">
              <form method="POST">
                <input name="register_email" class="form-control mb-3" type="email" placeholder="Email" required>
                <input name="register_password" class="form-control mb-3" type="password" placeholder="Mot de passe" required>
                <button type="submit" class="btn btn-primary w-100">Créer le compte</button>
              </form>
              <small class="text-muted d-block mt-2">⚠️ Accès accordé uniquement après validation.</small>
            </div>
          </div>
        </div>
      </div>
    </div>

        <form method="POST" class="mb-5">
      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label>Nom de la ligne</label>
          <input name="name" class="form-control" required value="<?= $current['name'] ?? '' ?>">
        </div>
        <div class="col-md-3">
          <label>Couleur</label>
          <input name="color" type="color" class="form-control form-control-color" value="<?= $current['color'] ?? '#3388ff' ?>">
        </div>
        <div class="col-md-3">
          <label>&nbsp;</label><br>
          <button type="button" id="gen" class="btn btn-success w-100">Générer l'itinéraire</button>
        </div>
      </div>

      <div id="map" class="mb-3"></div>

      <input type="hidden" name="departure" id="departure">
      <input type="hidden" name="arrival" id="arrival">
      <input type="hidden" name="coords" id="coords">

      <button type="submit" class="btn btn-primary"><?= $editId ? "📌 Modifier" : "➕ Enregistrer" ?></button>
      <?php if($editId):?><a href="manage_taxi.php" class="btn btn-secondary ms-2">↩️ Annuler</a><?php endif;?>
    </form>

    <h4 class="mb-3">📋 Lignes existantes</h4>
    <ul class="list-group">
      <?php foreach($lines as $l):?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
          <span><span style="background:<?= $l['color'] ?>;width:15px;height:15px;display:inline-block;margin-right:8px;"></span><?= htmlspecialchars($l['name']) ?></span>
          <span>
            <a href="manage_taxi.php?edit=<?= $l['id'] ?>" class="btn btn-sm btn-warning">Modifier</a>
            <a href="manage_taxi.php?delete=<?= $l['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Supprimer cette ligne ?')">Supprimer</a>
          </span>
        </li>
      <?php endforeach;?>
    </ul>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const map = L.map('map').setView([33.5731, -7.5898], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

let depMarker, arrMarker, routeLine;
let waypointMarkers = [];

const departureFld = document.getElementById('departure');
const arrivalFld = document.getElementById('arrival');
const coordsFld = document.getElementById('coords');
const existingPoints = <?= json_encode($points) ?>;

async function getLocationName(latlng) {
  const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${latlng.lat}&lon=${latlng.lng}`;
  const res = await fetch(url);
  const data = await res.json();
  return data.address?.suburb || data.address?.neighbourhood || data.address?.road || "Localisation inconnue";
}

// 🔁 Reconstruction du trajet en cas de modification
if (existingPoints.length >= 2) {
  const start = L.latLng(existingPoints[0][0], existingPoints[0][1]);
  const end = L.latLng(existingPoints[existingPoints.length - 1][0], existingPoints[existingPoints.length - 1][1]);
  const intermediates = existingPoints.slice(1, -1);

  depMarker = L.marker(start, { draggable: true }).addTo(map);
  departureFld.value = `${start.lat},${start.lng}`;
  getLocationName(start).then(name => depMarker.bindPopup(`Départ : ${name}`).openPopup());

  arrMarker = L.marker(end, { draggable: true }).addTo(map).bindPopup("Arrivée");
  arrivalFld.value = `${end.lat},${end.lng}`;

  intermediates.forEach(pt => {
    const marker = L.marker([pt[0], pt[1]], {
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
  });
  updateRouteLine();
}

map.on('click', e => {
  if (!depMarker) {
    depMarker = L.marker(e.latlng, { draggable: true }).addTo(map);
    departureFld.value = `${e.latlng.lat},${e.latlng.lng}`;
    getLocationName(e.latlng).then(name => {
      depMarker.bindPopup(`Départ : ${name}`).openPopup();
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
  if (!depMarker || !arrMarker) {
    alert("Sélectionnez départ et arrivée sur la carte.");
    return;
  }
  updateRouteLine();
};

async function updateRouteLine() {
  if (!depMarker || !arrMarker) return;
  const start = depMarker.getLatLng();
  const end = arrMarker.getLatLng();
  const dragPoints = waypointMarkers.map(m => m.getLatLng());
  const allPoints = [start, ...dragPoints, end];
  const coordString = allPoints.map(p => `${p.lng},${p.lat}`).join(';');
  const url = `https://router.project-osrm.org/route/v1/driving/${coordString}?overview=full&geometries=geojson`;

  try {
    const res = await fetch(url);
    const data = await res.json();
    const coords = data.routes[0].geometry.coordinates;
    if (routeLine) map.removeLayer(routeLine);
    routeLine = L.polyline(coords.map(c => [c[1], c[0]]), {
      color: document.querySelector('input[name=color]').value
    }).addTo(map);
    coordsFld.value = JSON.stringify(coords);
  } catch (e) {
    alert("Erreur de recalcul : " + e.message);
  }
}
</script>
</body>
</html>