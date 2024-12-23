<?php
declare (strict_types = 1);
namespace backup;
use think\facade\Config;
class Backup
{
    private $fp;
    /**
     * 备份文件信息 part - 卷号，name - 文件名
     * @var array
     */
    private $file;
    /**
     * 当前打开文件大小
     * @var integer
     */
    private $size = 0;

    /**
     * 数据库配置
     * @var integer
     */
    private $dbconfig = array();
    /**
     * 备份配置
     * @var integer
     */
    private $config = array(
		//数据库备份路径
        'path' => './backup/',
		//数据库备份卷大小,默认10MB
        'part' => 10485760,
        //数据库备份文件是否启用压缩 0不压缩 1 压缩
        'compress' => 0,
        //数压缩级别
        'level' => 9,
    );
    /**
     * 数据库备份构造方法
     * @param array  $file   备份或还原的文件信息
     * @param array  $config 备份配置信息
     */
    public function __construct($config = [])
    {
        $this->config = array_merge($this->config, $config);
        //初始化文件名
        $this->setFile();
        //初始化数据库连接参数
        $this->setDbConn();
        //检查文件是否可写
        if (!$this->checkPath($this->config['path'])) {
            throw new \think\Exception("The current directory is not writable");
        }
    }
    /**
     * 设置脚本运行超时时间
     * 0表示不限制，支持连贯操作
     */
    public function setTimeout($time=null)
    {
        if (!is_null($time)) {
           set_time_limit($time)||ini_set("max_execution_time", $time);
        }
        return $this;
    }
    /**
     * 设置数据库连接必备参数
     * @param array  $dbconfig   数据库连接配置信息
     * @return object
     */
    public function setDbConn($dbconfig = [])
    {
        if (empty($dbconfig)) {
            $this->dbconfig = Config::get('database');
        } else {
            $this->dbconfig = $dbconfig;
        }
        return $this;
    }
    /**
     * 设置备份文件名
     * @param Array  $file  文件名字
     * @return object
     */
    public function setFile($file = null)
    {
        if (is_null($file)) {
            $this->file = ['name' => date('Ymd-His'), 'part' => 1];
        } else {
            if (!array_key_exists("name", $file) && !array_key_exists("part", $file)) {
                $this->file = $file['1'];
            } else {
                $this->file = $file;
            }
        }
        return $this;
    }
    //数据类连接
    public static function connect()
    {
        return \think\facade\Db::connect();
    }
    //数据库表列表
    public function dataList($table = null,$type=1)
    {
        $db = self::connect();
        if (is_null($table)) {
            $list = $db->query("SHOW TABLE STATUS");
        } else {
            if ($type) {
                $list = $db->query("SHOW FULL COLUMNS FROM {$table}");
            }else{
                 $list = $db->query("show columns from {$table}");
            }
        }
        return array_map('array_change_key_case', $list);
        //$list;
    }
    //数据库备份文件列表
    public function fileList()
    {
        if (!is_dir($this->config['path'])) {
            mkdir($this->config['path'], 0755, true);
        }
        $path = realpath($this->config['path']);
        $flag = \FilesystemIterator::KEY_AS_FILENAME;
        $glob = new \FilesystemIterator($path, $flag);
        $list = array();
        foreach ($glob as $name => $file) {
            if (preg_match('/^\\d{8,8}-\\d{6,6}-\\d+\\.sql(?:\\.gz)?$/', $name)) {
                $name1= $name;
                $name = sscanf($name, '%4s%2s%2s-%2s%2s%2s-%d');
                $date = "{$name[0]}-{$name[1]}-{$name[2]}";
                $time = "{$name[3]}:{$name[4]}:{$name[5]}";
                $part = $name[6];
                if (isset($list["{$date} {$time}"])) {
                    $info = $list["{$date} {$time}"];
                    $info['part'] = max($info['part'], $part);
                    $info['size'] = $info['size'] + $file->getSize();
                } else {
                    $info['part'] = $part;
                    $info['size'] = $file->getSize();
                }
                $extension = strtoupper(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                $info['name']=$name1;
                $info['compress'] = $extension === 'SQL' ? '-' : $extension;
                $info['time'] = strtotime("{$date} {$time}");
                $list["{$date} {$time}"] = $info;
            }
        }
        return $list;
    }
    public function getFile($type = '', $time = 0)
    {
        //
        if (!is_numeric($time)) {
            throw new \think\Exception("{$time} Illegal data type");
        }
        switch ($type) {
            case 'time':
                $name = date('Ymd-His', $time) . '-*.sql*';
                $path = realpath($this->config['path']) . DIRECTORY_SEPARATOR . $name;
                return glob($path);
                break;
            case 'timeverif':
                $name = date('Ymd-His', $time) . '-*.sql*';
                $path = realpath($this->config['path']) . DIRECTORY_SEPARATOR . $name;
                $files = glob($path);
                $list = array();
                foreach ($files as $name) {
                    $basename = basename($name);
                    $match = sscanf($basename, '%4s%2s%2s-%2s%2s%2s-%d');
                    $gz = preg_match('/^\\d{8,8}-\\d{6,6}-\\d+\\.sql.gz$/', $basename);
                    $list[$match[6]] = array($match[6], $name, $gz);
                }
                $last = end($list);
                if (count($list) === $last[0]) {
                    return $list;
                } else {
                    throw new \think\Exception("File {$files['0']} may be damaged, please check again");
                }
                break;
            case 'pathname':
                return "{$this->config['path']}{$this->file['name']}-{$this->file['part']}.sql";
                break;
            case 'filename':
                return "{$this->file['name']}-{$this->file['part']}.sql";
                break;
            case 'filepath':
                return $this->config['path'];
                break;
            default:
                $arr = array('pathname' => "{$this->config['path']}{$this->file['name']}-{$this->file['part']}.sql", 'filename' => "{$this->file['name']}-{$this->file['part']}.sql", 'filepath' => $this->config['path'], 'file' => $this->file);
                return $arr;
        }
    }
    //删除备份文件
    public function delFile($time)
    {
        if ($time) {
            $file = $this->getFile('time', $time);
            array_map("unlink", $this->getFile('time', $time));
            if (count($this->getFile('time', $time))) {
                throw new \think\Exception("File {$path} deleted failed");
            } else {
                return $time;
            }
        } else {
            throw new \think\Exception("{$time} Time parameter is incorrect");
        }
    }
    /**
     * 下载备份
     * @param string $time
     * @param integer $part
     * @return array|mixed|string
     */
    public function downloadFile($time, $part = 0)
    {
        $file = $this->getFile('time', $time);
        $fileName = $file[$part];
        if (file_exists($fileName)) {
            ob_end_clean();
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($fileName));
            header('Content-Disposition: attachment; filename=' . basename($fileName));
            readfile($fileName);
        } else {
            throw new \think\Exception("{$time} File is abnormal");
        }
    }
    public function import($start,$time,$part)
    {
        //还原数据
        $db = self::connect();
        //$this->file=$this->getFile('time',$time);
		$file = $this->getFile('time', $time);
        $fileName = $file[$part-1];
        if (!file_exists($fileName)) {
			return false;
		}
        if ($this->config['compress']) {
            $gz = gzopen($fileName, 'r');
            $size = 0;
        } else {
            $size = filesize($fileName);
            $gz = fopen($fileName, 'r');
        }
        $sql = '';
        if ($start) {
            $this->config['compress'] ? gzseek($gz, $start) : fseek($gz, $start);
        }
        for ($i = 0; $i < 1000; $i++) {
            $sql .= $this->config['compress'] ? gzgets($gz) : fgets($gz);
            if (preg_match('/.*;$/', trim($sql))) {
                if (false !== $db->execute($sql)) {
                    $start += strlen($sql);
                } else {
                    return false;
                }
                $sql = '';
            } elseif ($this->config['compress'] ? gzeof($gz) : feof($gz)) {
                return 0;
            }
        }
        return array($start, $size);
    }
    /**
     * 写入初始数据
     * @return boolean true - 写入成功，false - 写入失败
     */
    public function Backup_Init()
    {
        $sql = "-- -----------------------------\n";
        $sql .= "-- Think MySQL Data Transfer \n";
        $sql .= "-- \n";
        $sql .= "-- \n";
        $sql .= "-- Part : #{$this->file['part']}\n";
        $sql .= "-- Date : " . date("Y-m-d H:i:s") . "\n";
        $sql .= "-- -----------------------------\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        return $this->write($sql);
    }
    /**
     * 备份表结构
     * @param  string  $table 表名
     * @param  integer $start 起始行数
     * @return boolean        false - 备份失败
     */
    public function backup($table, $start)
    {
        $db = self::connect();
        // 备份表结构
        if (0 == $start) {
            $result = $db->query("SHOW CREATE TABLE `{$table}`");
            $sql = "\n";
            $sql .= "-- -----------------------------\n";
            $sql .= "-- Table structure for `{$table}`\n";
            $sql .= "-- -----------------------------\n";
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= trim($result[0]['Create Table']) . ";\n\n";
            if (false === $this->write($sql)) {
                return false;
            }
        }
        //数据总数
        $result = $db->query("SELECT COUNT(*) AS count FROM `{$table}`");
        $count = $result['0']['count'];
        //备份表数据
        if ($count) {
            //写入数据注释
            if (0 == $start) {
                $sql = "-- -----------------------------\n";
                $sql .= "-- Records of `{$table}`\n";
                $sql .= "-- -----------------------------\n";
                $this->write($sql);
            }
            //备份数据记录
            $result = $db->query("SELECT * FROM `{$table}` LIMIT {$start}, 1000");
            foreach ($result as $row) {
                $sql = "INSERT INTO `{$table}` VALUES ('" . str_replace(array("\r", "\n"), array('\\r', '\\n'), implode("', '", $row)) . "');\n";
                if (false === $this->write($sql)) {
                    return false;
                }
            }
            //还有更多数据
            if ($count > $start + 1000) {
                return $this->backup($table, $start + 1000);
            }
        }
        //备份下一表
        return 0;
    }
    /**
     * 优化表
     * @param  String $tables 表名
     * @return String $tables
     */
    public function optimize($tables = null)
    {
        if ($tables) {
            $db = self::connect();
            if (is_array($tables)) {
                $tables = implode('`,`', $tables);
                $list = $db->query("OPTIMIZE TABLE `{$tables}`");
            } else {
                $list = $db->query("OPTIMIZE TABLE `{$tables}`");
            }
            if ($list) {
                return $tables;
            } else {
                throw new \think\Exception("data sheet'{$tables}'Repair mistakes please try again!");
            }
        } else {
            throw new \think\Exception("Please specify the table to be repaired!");
        }
    }
    /**
     * 修复表
     * @param  String $tables 表名
     * @return String $tables
     */
    public function repair($tables = null)
    {
        if ($tables) {
            $db = self::connect();
            if (is_array($tables)) {
                $tables = implode('`,`', $tables);
                $list = $db->query("REPAIR TABLE `{$tables}`");
            } else {
                $list = $db->query("REPAIR TABLE `{$tables}`");
            }
            if ($list) {
                return $list;
            } else {
                throw new \think\Exception("data sheet'{$tables}'Repair mistakes please try again!");
            }
        } else {
            throw new \think\Exception("Please specify the table to be repaired!");
        }
    }
    /**
     * 写入SQL语句
     * @param  string $sql 要写入的SQL语句
     * @return boolean     true - 写入成功，false - 写入失败！
     */
    private function write($sql)
    {
        $size = strlen($sql);
        //由于压缩原因，无法计算出压缩后的长度，这里假设压缩率为50%，
        //一般情况压缩率都会高于50%；
        $size = $this->config['compress'] ? $size / 2 : $size;
        $this->open($size);
        return $this->config['compress'] ? @gzwrite($this->fp, $sql) : @fwrite($this->fp, $sql);
    }
    /**
     * 打开一个卷，用于写入数据
     * @param  integer $size 写入数据的大小
     */
    private function open($size)
    {
        if ($this->fp) {
            $this->size += $size;
            if ($this->size > $this->config['part']) {
                $this->config['compress'] ? @gzclose($this->fp) : @fclose($this->fp);
                $this->fp = null;
                $this->file['part']++;
                session('backup_file', $this->file);
                $this->Backup_Init();
            }
        } else {
            $backuppath = $this->config['path'];
            $filename = "{$backuppath}{$this->file['name']}-{$this->file['part']}.sql";
            if ($this->config['compress']) {
                $filename = "{$filename}.gz";
                $this->fp = @gzopen($filename, "a{$this->config['level']}");
            } else {
                $this->fp = @fopen($filename, 'a');
            }
            $this->size = filesize($filename) + $size;
        }
    }
    /**
     * 检查目录是否可写
     * @param  string   $path    目录
     * @return boolean
     */
    protected function checkPath($path)
    {
        if (is_dir($path)) {
            return true;
        }
        if (mkdir($path, 0755, true)) {
            return true;
        } else {
            return false;
        }
    }
    /**
     * 析构方法，用于关闭文件资源
     */
    public function __destruct()
    {
        if($this->fp){
            $this->config['compress'] ? @gzclose($this->fp) : @fclose($this->fp);
        }
    }
	
	//判断某数据表是否存在
	public function check_table($table){
		$prefix = config('database.connections.mysql.prefix');
		$db = self::connect();
        $res = $db->query('SHOW TABLES LIKE '."'".$prefix.$table."'");
        if($res){
            return 1;
        }else{
            return 0;
        }
    }
	//判断某数据表中某字段是否存在
	public function check_column($table,$column){
		$prefix = config('database.mysql.prefix');
        $db = self::connect();
        $res = $db->query('select count(*) from information_schema.columns where table_name = '."'".$table."' ". 'and column_name ='."'".$column."'");
        if($res[0]['count(*)'] != 0){
            return 1;
        }else{
            return 0;
        }
    }
	
	//添加字段
	public function add_column($table,$column,$type,$condition,$after){
        $db = self::connect();
        $res = $db->execute('alter table'." `".$table."` ".'add'." `".$column."` ".$type." ".$condition." ".'after'." `".$after."`");
        if($res){
            return 1;
        }else{
            return 0;
        }
    }
	
	//删除字段
	public function del_column($table,$column){
        $db = self::connect();
        $res = $db->execute('alter table '."`".$table."`".' drop column'."`".$column."`");
        if($res){
            return 1;
        }else{
            return 0;
        }
    }
	
	//修改字段
	public function update_column($table,$old_column,$column,$type){
        //字段名和类型同时修改才会返回1不然返回0
        $db = self::connect();
        $res = $db->execute('alter table' ." `".$table."` ". 'change' ." `".$old_column."` " ."`".$column."`" .$type);
        if($res){
            return 1;
        }else{
            return 0;
        }
    }
	//执行sql
	public function run_sql($sql){
		$prefix = config('database.connections.mysql.prefix');
		$db = self::connect();
		$sql_array = preg_split("/;[\r\n]+/", str_replace("oa_", $prefix, $sql));
		foreach ($sql_array as $k => $v) {
			if (!empty($v)) {				
				try {
					$res = $db->query($v);
				} catch (\Exception $e) {
					return 0;
					break;
				}
			}
		}
        return 1;
    }
}
