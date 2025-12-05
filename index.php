<?php

if (PHP_VERSION_ID < 80000) {
    die('Required PHP Version 8.0 or later.');
}

// -- CONFIG -- 
const PASSWORD = '24tgdrwtq3qhe4r';
const HOME_DIR = __DIR__;
// -- CONFIG -- 




$cmd = new Cmd();

$filemanager = new FileManager(home: HOME_DIR);

function authMiddleware()
{
    if (request(header: 'Authorization') !== PASSWORD) {
        response(['success' => false, 'message' => 'Unauthenticated request'], 401);
    }
    return true;
}

$cmd->register('ls', 'get', function () use ($filemanager) {
    $path = request(getfield: 'path') ?? '~';

    try {
        $ls = ($path === '~') ? $filemanager->ls(false) : $filemanager->ls(false, urldecode($path));

        return [
            'success' => true,
            'dir' => ($path === '~') ? $filemanager->home : $filemanager->get_actual_dir($path),
            'list' => $ls
        ];
    } catch (\Exception $e) {
        response([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}, authMiddleware(...));


$cmd->register('mkdir/{dirname}', 'get', function ($dirname) use ($filemanager) {
    $path = request(getfield: 'path') ?? '~';

    try {
        $res = $filemanager->mkdir($filemanager->get_actual_dir(rtrim($path, '/') . '/' . $dirname));

        if (!$res) {
            response([
                'success' => false,
                'message' => sprintf("Cannot create directory ‘%s’: File or directory may already exists.", $dirname)
            ], 403);
        }

        response([
            'success' => true,
            'message' => sprintf("Directory %s created successfully", $dirname)
        ], 201);
    } catch (\Exception $e) {
        response([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}, authMiddleware(...));


$cmd->register('newfile/{fname}', 'get', function ($fname) use ($filemanager) {
    $path = request(getfield: 'path') ?? '~';

    try {
        $res = $filemanager->newfile($filemanager->get_actual_dir(rtrim($path, '/') . '/' . $fname));

        if ($res) {
            response([
                'success' => true,
                'message' => sprintf("File ‘%s’ created successfully", $fname)
            ], 201);
        } else {
            response([
                'success' => false,
                'message' => sprintf("Cannot create file ‘%s’: File may already exists.", $fname)
            ], 500);
        }
    } catch (\Exception $e) {
        response([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}, authMiddleware(...));

$cmd->register('touch/{fname}', 'get', function ($fname) use ($filemanager) {
    $path = request(getfield: 'path') ?? '~';

    try {
        $filemanager->touch($filemanager->get_actual_dir(rtrim($path, '/') . '/' . $fname));

        response([
            'success' => true,
            'message' => sprintf("%s `%s` touched successfully", is_file($path) ? 'File' : 'Directory', $fname)
        ], 201);
    } catch (\Exception $e) {
        response([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}, authMiddleware(...));


$cmd->register('rm', 'delete', function () use ($filemanager) {
    $path = request(getfield: 'path');

    if (!$path)
        response([
            'success' => false,
            'message' => 'No path specified'
        ], 500);


    $res = $filemanager->rm($path);

    if (!$res) {
        if (is_dir($path)) {
            response([
                'success' => false,
                'message' => "Can't remove directory. The directory may not be empty or you may not have permission to delete it."
            ], 403);
        } else {
            response([
                'success' => false,
                'message' => "Can't delete file. You may not have permission to delete it."
            ], 403);
        }
    } else {
        if (is_file($path)) {
            response([
                'success' => true,
                'message' => 'File deleted successfully'
            ], 202);
        } else {
            response([
                'success' => true,
                'message' => 'Directory deleted successfully'
            ], 202);
        }
    }
}, authMiddleware(...));


$cmd->register('cat', 'get', function () use ($filemanager) {
    $path = request(getfield: 'path');

    if (!$path)
        response([
            'success' => false,
            'message' => 'No path specified'
        ], 500);

    if (!is_file($path))
        response([
            'success' => false,
            'message' => 'Path is not a file'
        ], 500);

    try {
        $res = $filemanager->get_contents($path);
        if ($res === false) {
            response([
                'success' => false,
                'message' => 'File does not exists'
            ], 500);
        } else {
            response([
                'success' => true,
                'content' => $res
            ], 500);
        }
    } catch (\Exception $e) {
        response([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}, authMiddleware(...));


$cmd->register('put_data', 'post', function () use ($filemanager) {
    $path = request(getfield: 'path');
    $content = request(postfield: 'content');

    if (!$path)
        response([
            'success' => false,
            'message' => 'No path specified'
        ], 500);

    if (!is_file($path))
        response([
            'success' => false,
            'message' => 'Path is not a file'
        ], 500);


    try {
        if (!$filemanager->put_contents($path, $content)) {
            response([
                'success' => false,
                'message' => "Something went wrong, couldn't save file."
            ], 403);
        } else {
            response([
                'success' => true,
                'message' => "File saved successfully."
            ], 202);
        }
    } catch (\Exception $e) {
        response([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}, authMiddleware(...));


$cmd->register('dir_size', 'get', function () use ($filemanager) {
    $path = request(getfield: 'path') ?? '~';

    try {
        return [
            'success' => false,
            'data' => $filemanager->dir_size($path)
        ];
    } catch (\Exception $e) {
        response([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}, authMiddleware(...));

$cmd->serve();



// ------------------------------------------------------------------------------
// ----------------------------- File Manager class -----------------------------
// ------------------------------------------------------------------------------

class FileManager
{

    public $home = __DIR__;

    public function __construct(?string $home = null)
    {
        $this->home = is_dir($home) ? $home : __DIR__;
    }

    public function cd(?string $home = null)
    {
        $this->home = is_dir($home) ? $home : __DIR__;
    }

    public function get_actual_dir($path)
    {
        if (str_starts_with($path, '~/')) {
            $path = rtrim($this->home, '/') . substr($path, 1);
        }
        return $path;
    }

    public function ls(?bool $exclude_hiddens = true, ?string $dir = null)
    {
        $dir ??= $this->home;

        $dir = $this->get_actual_dir($dir);

        if (is_file($dir))
            throw new Exception("Requested path is not a directory.");

        if (!is_dir($dir))
            throw new Exception("No such directory found.");

        $list = scandir($dir);

        if ($exclude_hiddens) {
            $list = array_filter($list, fn($f) => $f[0] !== '.');
        }

        $final_list = [];

        foreach ($list as $node) {
            if ($node === '.') {
                $now = rtrim($dir, '/') . '/'; // get current directory
            } else if ($node === '..') {
                $now = dirname(rtrim($dir, '/')) . '/'; // get previous directory
            } else {
                $now = rtrim($dir, '/') . '/' . $node;
            }

            if (is_file($now)) {
                $final_list[] = [
                    'type' => 'file',
                    'path' => $now,
                    'name' => $node,
                    'size' => filesize($now),
                    'human_readable_size' => $this->human_readable_filesize(filesize($now)),
                    'mtime' => filemtime($now),
                    'ctime' => filectime($now),
                    'mod' => substr(sprintf('%o', fileperms($now)), -4)
                ];
            } else {
                $final_list[] = [
                    'type' => 'folder',
                    'path' => $now,
                    'name' => $node,
                    'mtime' => filemtime($now),
                    'ctime' => filectime($now),
                    'mod' => substr(sprintf('%o', fileperms($now)), -4)
                ];
            }
        }

        return $final_list;
    }

    public function create(string $dir, string $file)
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $fp = fopen(sprintf("%s/%s", $dir, $file), 'w');
        fclose($fp);
        return sprintf("%s/%s", $dir, $file);
    }

    public function mkdir(string $path)
    {
        if (is_dir($path)) {
            return false;
        }
        return mkdir($path, 0777, true);
    }

    public function newfile(string $path)
    {
        if (file_exists($path)) {
            return false;
        }

        return $this->touch($path);
    }

    public function touch(string $path)
    {
        return touch($path);
    }

    public function rm(string $path)
    {
        try {
            if (is_dir($path)) {
                rmdir($path);
                return true;
            } else if (file_exists($path)) {
                unlink($path);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }

    }

    public function exists(string $path)
    {
        if (is_dir($path)) {
            return true;
        } else if (file_exists($path)) {
            return true;
        }
        return false;
    }

    public function get_contents(string $path)
    {
        if (!file_exists($path))
            throw new \Exception("File does not exists.");

        try {
            return file_get_contents($path);
        } catch (Exception $e) {
            return false;
        }

    }

    public function put_contents(string $path, string $content)
    {
        if (!file_exists($path))
            throw new \Exception("File does not exists.");

        try {
            file_put_contents($path, $content);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function dir_size(string $path): int
    {
        if (!is_dir($path))
            return 0;

        $size = 0;
        $items = scandir($path);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..')
                continue;

            $full = "$path/$item";

            if (is_dir($full)) {
                $size += $this->dir_size($full);
            } else {
                $size += filesize($full);
            }
        }

        return $size;
    }

    public function human_readable_filesize($bytes, $decimals = 2)
    {
        $sz = 'BKMGTP';
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
    }
}


// ------------------------------------------------------------------------------
// ---------------------------------- API Core ----------------------------------
// ------------------------------------------------------------------------------

class Cmd
{
    private array $cmds = [];

    /**
     * Register a command
     */
    public function register(string $cmd, string $method, callable $callable, ?callable $middleware = null)
    {
        $method = strtolower($method);

        $pattern = preg_replace('~\{([^/]+)\}~', '(.+)', $cmd);
        $pattern = "~^" . $pattern . "$~";

        $this->cmds[] = [
            'method' => $method,
            'cmd' => $cmd,
            'pattern' => $pattern,
            'callable' => $callable,
            'middleware' => $middleware,
        ];
    }

    public function serve()
    {
        $currentPath = $_GET['cmd'] ?? '';
        $currentPath = trim($currentPath, '/');
        $requestMethod = strtolower($_POST['_method'] ?? $_SERVER['REQUEST_METHOD']);

        $allowedMethods = [];

        foreach ($this->cmds as $r) {

            if (!preg_match($r['pattern'], $currentPath, $matches)) {
                continue;
            }

            // matched path, but maybe wrong method
            if ($r['method'] !== $requestMethod) {
                $allowedMethods[] = strtoupper($r['method']);
                continue;
            }

            // extract params
            array_shift($matches);
            $params = $this->extractParams($r['cmd'], $matches);

            // process middleware
            if ($r['middleware']) {
                $mwResult = call_user_func($r['middleware'], ...$params);

                if ($mwResult === false) {
                    response([
                        'error' => 'Invalid request'
                    ], 400);
                    return;
                }
            }

            // call the cmd
            $result = call_user_func($r['callable'], ...$params);

            // auto output JSON if array
            if (is_array($result)) {
                response($result);
            } else {
                echo $result;
            }

            return;
        }

        if (!empty($allowedMethods)) {
            response(
                ['error' => 'Method Not Allowed', 'allowed' => $allowedMethods],
                405
            );
        }

        response(['error' => 'Not Found'], 404);
    }

    private function extractParams(string $cmd, array $matches): array
    {
        preg_match_all('~\{([^/]+)\}~', $cmd, $paramNames);
        $paramNames = $paramNames[1];
        $params = [];

        foreach ($paramNames as $i => $name) {
            $params[$name] = urldecode($matches[$i]); // decoded
        }

        return $params;
    }
}


// ------------------------------------------------------------------------------
// ------------------------------ Helper functions ------------------------------
// ------------------------------------------------------------------------------

function response(array $data, int $status = 200)
{
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit; // stop further execution
}

function request(
    ?string $getfield = null,
    ?string $postfield = null,
    ?string $header = null,
    bool $json = false
) {
    // GET
    if ($getfield !== null) {
        return $_GET[$getfield] ?? null;
    }

    // POST
    if ($postfield !== null) {
        return $_POST[$postfield] ?? null;
    }

    // header
    if ($header) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        return $_SERVER[$key] ?? null;
    }

    // JSON body
    if ($json) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        return $decoded ?? [];
    }

    return null;
}
