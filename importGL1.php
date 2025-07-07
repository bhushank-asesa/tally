<?php
// import masters actual code 2
ini_set('memory_limit', '2048M');
ini_set('max_input_vars', '600'); // Example: Set max input variables to 3000
ini_set('max_execution_time', '900');
try {
    $pdo = new PDO("mysql:host=localhost;dbname=tally", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    function buildHierarchyPath($pdo, $name)
    {
        $path = [];
        $topParent = null;
        while ($name) {
            $stmt = $pdo->prepare("SELECT name, parent_name FROM tally_hierarchy WHERE name = :name LIMIT 1");
            $stmt->execute([':name' => $name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row)
                break;
            array_unshift($path, $row['name']);
            $topParent = $row['name'];
            $name = $row['parent_name'];
        }
        return ['path' => json_encode($path), 'top' => $topParent];
    }
    $page = 10;
    $selectAll = $pdo->query("select id, name FROM tally_hierarchy limit $page, 2000");
    $update = $pdo->prepare("update tally_hierarchy SET path = :path, top_parent = :top WHERE id = :id");

    foreach ($selectAll as $row) {
        $hierarchy = buildHierarchyPath($pdo, $row['name']);
        $update->execute([
            ':path' => $hierarchy['path'],
            ':top' => $hierarchy['top'],
            ':id' => $row['id']
        ]);
    }

    echo "✅ Done! Imported hierarchy with full_path and top_parent for page $page.\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . " at line " . $e->getLine() . "\n";
}
