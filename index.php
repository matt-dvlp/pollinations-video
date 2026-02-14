<?php
session_start();

/* -------------------------
   REMOVE KEY
--------------------------*/
if (isset($_GET['remove_key'])) {
    unset($_SESSION['api_key']);
    header("Location: index.php");
    exit;
}

/* -------------------------
   SAVE KEY
--------------------------*/
if (isset($_POST['save_key'])) {
    $_SESSION['api_key'] = trim($_POST['api_key']);
    header("Location: index.php");
    exit;
}

/* -------------------------
   GET BALANCE
--------------------------*/
$balance = null;
$balanceError = null;

if (!empty($_SESSION['api_key'])) {

    $ch = curl_init("https://gen.pollinations.ai/account/balance");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $_SESSION['api_key']
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $balance = $data['balance'] ?? 0;
    } elseif ($httpCode === 403) {
        $balanceError = "No balance permission";
    } else {
        $balanceError = "Unavailable";
    }
}

/* -------------------------
   GENERATE VIDEO
--------------------------*/
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['generate'])) {

    if (empty($_SESSION['api_key'])) {
        http_response_code(401);
        echo json_encode(["error" => "API key missing"]);
        exit;
    }

    $apiKey = $_SESSION['api_key'];
    $prompt = trim($_POST["prompt"]);
    $model = $_POST["model"];
    $width = $_POST["width"];
    $duration = $_POST["duration"];

    if (empty($prompt)) {
        http_response_code(400);
        echo json_encode(["error" => "Prompt required"]);
        exit;
    }

    $encodedPrompt = rawurlencode($prompt);

    $queryParams = [
        "model" => $model,
        "width" => $width,
        "duration" => $duration,
        "seed" => -1
    ];

    $apiUrl = "https://image.pollinations.ai/image/" . $encodedPrompt . "?" . http_build_query($queryParams);

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiKey"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {

        $file = "video_" . time() . ".mp4";
        file_put_contents($file, $response);

        echo json_encode([
            "success" => true,
            "file" => $file
        ]);

    } else {

        http_response_code($httpCode);
        echo json_encode([
            "error" => "API Error ($httpCode)",
            "details" => $response
        ]);
    }

    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Pollinations Video Generator</title>
<style>
body {
    background: #121212;
    color: #eee;
    font-family: Arial;
    max-width: 800px;
    margin: 40px auto;
}

textarea, select, input {
    width: 100%;
    margin-bottom: 10px;
    padding: 8px;
    background: #1e1e1e;
    color: #fff;
    border: 1px solid #333;
}

button {
    padding: 8px 16px;
    cursor: pointer;
    background: #E7C35A;
    border: none;
    color: #000;
    font-weight: bold;
}

button:hover {
    opacity: 0.9;
}

.box {
    padding: 15px;
    border: 1px solid #333;
    margin-bottom: 20px;
    border-radius: 8px;
    background: #1a1a1a;
}

a { color: #ff6b6b; text-decoration: none; }

.loader {
  border: 5px solid #333;
  border-top: 5px solid #E7C35A;
  border-radius: 50%;
  width: 35px;
  height: 35px;
  animation: spin 1s linear infinite;
  margin-top: 15px;
}

@keyframes spin {
  100% { transform: rotate(360deg); }
}

#spinner { display: none; }
video { margin-top: 10px; border-radius: 6px; }
</style>
</head>
<body>

<h2>🎬 Pollinations Video Generator</h2>

<?php if (empty($_SESSION['api_key'])): ?>

<div class="box">
    <h3>Enter your API key</h3>
    <p>Example: sk_abc...123</p>
    <form method="POST">
        <input type="text" name="api_key" required placeholder="sk_...">
        <button type="submit" name="save_key">Save Key</button>
    </form>
</div>

<?php else: ?>

<div class="box">
    <strong>Key:</strong>
    <?php echo substr($_SESSION['api_key'], 0, 6) . "..."; ?>

    |
    <strong>Balance:</strong>
    <?php
        if ($balance !== null) echo $balance . " pollen";
        else echo $balanceError;
    ?>

    <a href="?remove_key=1" style="float:right;">✖ Remove</a>
</div>

<div class="box">
    <form id="generateForm">
        <label>Prompt</label>
        <textarea name="prompt" rows="4" required></textarea>

        <label>Model</label>
        <select name="model">
            <optgroup label="Free Models">
                <option value="grok-video">Grok Video</option>
                <option value="seedance">Seedance Lite</option>
                <option value="wan">Wan 2.6</option>
            </optgroup>
        
            <optgroup label="Paid Models">
                <option value="ltx-2">LTX-2 (paid only)</option>
                <option value="seedance-pro">Seedance Pro-Fast (paid only)</option>
                <option value="veo">Veo 3.1 Fast (paid only)</option>
            </optgroup>
        </select>

        <label>Width</label>
        <select name="width">
            <option value="512">512</option>
            <option value="768">768</option>
            <option value="1024" selected>1024</option>
            <option value="1280">1280</option>
        </select>

        <label>Duration (seconds)</label>
        <select name="duration">
            <?php for ($i = 1; $i <= 10; $i++): ?>
                <option value="<?php echo $i, $i==4 ?? ' selected'; ?>"><?php echo $i; ?> sec</option>
            <?php endfor; ?>
        </select>

        <button type="submit" name="generate">Generate Video</button>
    </form>

    <div id="spinner">
        <div class="loader"></div>
        <p>Generating video...</p>
    </div>

    <div id="result"></div>
</div>

<script>
document.getElementById("generateForm").addEventListener("submit", function(e) {
    e.preventDefault();

    const spinner = document.getElementById("spinner");
    const result = document.getElementById("result");

    spinner.style.display = "block";
    result.innerHTML = "";

    const formData = new FormData(this);
    formData.append("generate", "1");

    fetch("", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {

        if (data.success) {
            result.innerHTML = `
                <h3>Result</h3>
                <video width="100%" controls>
                    <source src="${data.file}" type="video/mp4">
                </video>
                <br><br>
                <a href="${data.file}" download>
                    <button>⬇ Download Video</button>
                </a>
            `;
        } else {
            result.innerHTML = `
                <p style="color:red;"><strong>${data.error}</strong></p>
                <pre>${data.details ?? ""}</pre>
            `;
        }

    })
    .finally(() => {
        spinner.style.display = "none";
    });
});
</script>

<?php endif; ?>

</body>
</html>
