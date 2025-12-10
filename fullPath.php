<?php
// import masters actual code 2
ini_set('memory_limit', '2048M');
ini_set('max_input_vars', '600'); // Example: Set max input variables to 3000
ini_set('max_execution_time', '2100');
ini_set('post_max_size', '2048M');
ini_set('upload_max_filesize', '2048M');
try {
    $pdo = new PDO("mysql:host=localhost;dbname=wasan_tally_dec", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $str = "";
    function buildHierarchyPath($pdo, $name, &$str)
    {
        $path = [];
        $topParent = null;
        while ($name) {
            $str .= "<br/>In While for $name -> ";
            $stmt = $pdo->prepare("select name, parent_name FROM ledgers WHERE tally_company_id = 2 and name = :name LIMIT 1");
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
    $page = 1;
    $limit = 2500;
    $offset = ($page - 1) * $limit;
    $selectAll = $pdo->query("select id, name FROM ledgers where tally_company_id = 2 and path is null order by id asc limit $offset, $limit");
    $update = $pdo->prepare("update ledgers SET path = :path, top_parent = :top WHERE id = :id");

    foreach ($selectAll as $row) {
        $str .= "<br/> Update for " . $row['id'] . " = " . $row['name'];
        $hierarchy = buildHierarchyPath($pdo, $row['name'], $str);
        $update->execute([
            ':path' => $hierarchy['path'],
            ':top' => $hierarchy['top'],
            ':id' => $row['id']
        ]);
    }

    echo "✅ Done! Imported hierarchy with full_path and top_parent for page $page.<br/>";
    echo $str;
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . " at line " . $e->getLine() . "\n";
}
