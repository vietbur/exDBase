<?php

class exDBase
{
    private $DB = false;
    private $limit = '';
	public $error='';
    public $query='';

	public function __construct ($host, $username, $passwd, $dbname = "", $utf = true, $port = false, $socket = false)
    // инициирует новый объект класса exDBase и присоединяется к базе данных MySQL
    // все параметры стандартные как для создания нового объекта mysqli
    // если параметр $utf установлен в true, будет осуществлена настройка сортировки и вывода данных таблиц в формате Unicode
	{
        $this->open ($host, $username, $passwd, $dbname, $utf, $port, $socket);
	} 

    public function open ($host, $username, $passwd, $dbname = "", $utf = true, $port = false, $socket = false)
    // Создает новое подключение к базе данных MySQL, предварительно закрывая старое подключение, если оно открыто.
    // все параметры стандартные как для создания объекта mysqli
    // если параметр $utf установлен в true, будет осуществлена настройка сортировки и вывода данных таблиц 
    // в формате Unicode
    {
        if ($this->DB)
            $this->close();
        $DB = new mysqli ($host, $username, $passwd, $dbname, $port, $socket);
		if ($DB->connect_error)
        {
			$this->error = $DB->connect_error;
            return false;
        }
        $this->DB = $DB;
        if ($utf)
        {
            $this->DB->set_charset('utf8');
            $this->DB->query ("SET character_set_results = 'utf8', character_set_client = 'utf8', character_set_connection = 'utf8', character_set_database = 'utf8', character_set_server = 'utf8', collation_connection = 'utf8_general_ci'");
        }
        return true;
    }
    
    public function close ()
    // Если имеется установленное соединение с базой MySQL, то закрывает его
    {
        if ($this->DB)
            $this->DB->close();
        $this->DB = false;
        $this->error = '';
        $this->query = '';
    }
    
    public function checkSign ($value)
    // Вспомогательная функция. Парсит знаки сравнения для параметра WHERE
    {
        $signs = array (">=", "<=", "<>", ">", "<", "=", "!=");
        $value = trim ($value);
        $result = false;
        if (strpos ($value, '(') !== false)
            $result['open_bracket'] = '(';
        elseif (strpos ($value, ')') !== false)
            $result['close_bracket'] = ')';
        if (strpos ($value, '||') !== false)
            $result['glue'] = ' OR ';
        $value = str_replace (array ('(', ')', '||'), '', $value);
        foreach ($signs as $s)
        {
            if (strpos ($value, $s) === 0)
            {
                $result['value'] = substr ($value, strlen ($s));
                $result['prefix'] = $s;
                break;
            }
        }
        if (!$result['prefix'])
        {
            $result['value'] = $value;
            $result['prefix'] = '';
        }
        return $result;
    }

    protected function quoted ($value, $double = false)
    // Вспомогательная функция. Добавляет одинарные (или двойные если $double=true) кавычки в начале и в конце строки, если $value является строкой
    {
        if (!is_string($value))
            return $value;
        elseif ($double)
            return '"' . $value . '"';
        else
            return "'" . $value . "'";
    }

    protected function escape($value, $escape = true)
    // экранирует служебные символы в строке, тем самым не давая возможности инъекций в MySQL
    // если $escape установлен в false, то экранирования не происходит
    {
        if (!$escape )
            return $value;
        // не строковые значения, а также строки в формате JSON не экранируются
        if (!is_string ($value) || is_object(json_decode($value)) || is_array(json_decode($value)))
            return $value;
        if ($this->DB)
            return $this->DB->real_escape_string($value);
        else
            return str_replace(array("'",'"'), '', $value);
    }
    
    protected function noTable ()
    // устанавливает флаг ошибки в случае, если в запросе не определено имя таблицы
    {
        $this->error = "No table specified";
        return false;
    }
    
    protected function noData ()
    // устанавливает флаг ошибки в случае, если в запросе отсутствуют необходимые поля данных или они ошибочны
    {
        $this->error = "No fields/data specified";
        return false;
    }
    
    protected function noDB ()
    // устанавливает флаг ошибки при запросе в случае, если база данных не открыта
    {
        $this->error = "No database open";
        return false;
    }
    
    function query ($qry)
    // Выполняет запрос $qry в текущую открытую базу MySQL, возвращает его результат или false в случае неудачи
    {
        if ($this->DB)
        {
            $result = $this->DB->query ($qry);
            $this->error = $this->DB->error;
            $this->query = $qry;
            return $result;
        }
        else return $this->noDB(); 
    }
    
	public function parse ($fields, $type = 0)
    // Вспомогательная функция
    // разбирает набор параметров в строку, алгоритм зависит от параметра $type
    // $type = 0 - тип SELECT WHERE, результат в виде FIELD1='value1' AND/OR FIELD2>= value2
    // $type = 1 - тип INSERT, результат в виде (FIELD1, FIELD2) ('value1', value2)
    // $type = 2 - тип UPDATE, результат в виде "FIELD1=value1, FIELD2='value2'" 
    // $type = 3 - тип поля ORDER BY, результат в виде "FIELD1 value1, FIELD2 value2"   
    // $type = 4 - обычная разворотка, результат в виде "value1, value2, value3"
	{
        if (is_string($fields))
            return $fields;
        elseif (empty($fields) || !is_array ($fields))
            return false;
        // экранируем значения и добавляем кавычки по необходимости
        foreach ($fields as $key => $value)
        {
            $fields[$key] = $this->escape ($value);
            if ($type < 3)
                // для SELECT WHERE и для INSERT/UPDATE заключаем строки в кавычки
                $fields[$key] = $this->quoted($fields[$key]);
        }
        switch ($type)
        {
            case 1:
                $result['keys'] = implode(", ", array_keys ($fields));
                $result['values'] = implode(", ", $fields);
                break;
            case 4:
                $result = implode(", ", $fields);
                break;
            case 0:
            case 2:
            case 3:
                $result = '';
                $glues = array ('0' => array ('=', ' AND '), '2' => array ('=', ', '), '3' => array (' ', ', '));
                foreach ($fields as $key => $value)
                {
                    $key = trim ($key);
                    $glue = $glues[$type][1];
                    $res = [];
                    if (!$type)
                    {
                        $res = $this->checkSign ($key);
                        $key = $res['value'];
                        if ($res['glue'])
                        // у ключа стоит модификатор OR
                            $glue = $res['glue'];
                    }
                    if (!$res['prefix']) $res['prefix'] = $glues[$type][0];
                    if (!$result) $glue = '';
                    $result .= $glue . $res['open_bracket'] . $key . $res['prefix'] . $value . $res['close_bracket'];
                }
        }
        return $result;
    }

    public function setLimit ($limit)
    {
        if (!$limit)
            $this->limit = '';
        else
           $this->limit = " LIMIT " . $limit; 
    }
    
    public function select ($table, $where = false, $fields = '*', $order = false)
    // Внутренняя функция. Используйте fetchFirst или fetchArray
    // Метод для выбора значений из таблицы, возвращает ссылку на массив данных как в mysqli
    // $table - имя таблицы
    // $where - либо false (тогда выбираются все значения) либо массив вида ('FIELD' = > value, ...), либо строка вида "FIELD = value, ..."
    // $fields - поля для выбора, массив вида array ('FIELD1', 'FIELD2', ...) либо строка "FIELD1, FIELD2, ..."
    // $order - порядок сортировки, массив вида array ('FIELD1' =>'ASC', 'FIELD2'=>'DESC') либо строка
    {
        $result = false;
        $fields = $this->parse ($fields, 4);
        $table = $this->escape ($table);
        $where = $this->parse ($where);
        if ($where)
            $where = "WHERE $where";
        $order = $this->parse ($order, 3);
        if ($order)
            $order = "ORDER BY $order";   
        if (!$table)
            return $this->noTable();
        if (!$fields)
            $fields = '*';
        $qry = "SELECT $fields FROM $table $where $order $this->limit";
        $this->limit = '';
		return $this->query ($qry);
    }
    
    function fetchFirst ($table, $where = false, $fields = '*', $order = false)
    // то же самое, что метод select, но выбор только первого элемента, возвращает асссоциативный массив для первой записи
    {
        $result = false;
        if ($res = $this->select($table, $where, $fields, $order))
		{
            $result = $res->fetch_assoc();
		}
        return $result;
    }

    function fetchArray ($table, $where = false, $fields = '*', $order = false)
    // метод для выбора значений из таблицы, возвращает ссылку на массив ассоциативных массивов всех данных
    {
        $result = false;
		if ($res = $this->select($table, $where, $fields, $order))
		{
        	while ($rec = $res->fetch_assoc())
				$result[] = $rec;
		}
        return $result;
    }

    function fetchObject ($table, $where = false, $order = false)
    // то же самое, что и fetchArray только возвращает данные в виде массива объектов
    {
        $result = false;
		if ($res = $this->select($table, $where, '*', $order))
		{
        	while ($rec = $res->fetch_object())
				$result[] = $rec;
			$res->free();
		}
    }
    
    function fetchIDs ($table, $where = false, $id="ID", $order = false)
    // выбирает из результатов запроса только идентификаторы, по умолчанию поле ID, но можно задать в переменной
    // $id и другое имя поля
    {
        $result = false;
		if ($res = $this->select($table, $where, $id, $order))
		{
        	while ($rec = $res->fetch_assoc())
				$result[] = $rec [$id];
		}
        return $result;
    }

    function update ($table, $where = false, $fields = false)
    // обновление данных в таблице $table, поля и значения задаются ассоциативным массивом или строкой
    {
        if (!is_array ($fields))
            return $this->noData();
        $table = $this->escape($table); 
        if (!$table)
            return $this->noTable();
		$res = $this->parse ($fields, 2);
		if ($res)
        {
            $where = $this->parse ($where);
            if ($where)
                $where = "WHERE $where";
            $qry = "UPDATE $table SET $res $where";
            return $this->query ($qry);
        }
        else 
            return $this->noData();
	} 
    
    function insert ($table, $fields = false, $replace = false)
    // вставка новой записи в таблицу $table, поля и значения задаются ассоциативным массивом или строкой
    // возвращает идентификатор вставленной записи
	{
        if (!is_array ($fields))
            return $this->noData();
		$res = $this->parse ($fields, 1);
		if ($res)
		{
			$f = $res ['keys'];
			$v = $res ['values'];
            $table = $this->escape($table);  
            if (!$table)
                return $this->noTable();
            if (!$f || !$v)
                return $this->noData();
            $qry = ($replace ? 'REPLACE' : 'INSERT') . " INTO $table ($f) VALUES ($v)";
            $this->query ($qry);
			return $this->insertID ();
        }
        else return $this->noData();
	} 
  
    function replace ($table, $fields = false)
    // замена существующей записи в таблицу $table, поля и значения задаются ассоциативным массивом или строкой
	{
		return $this->insert ($table, $fields, true);
	}

    function insertID ()
    // возвращает номер последней вставленной записи
	{
		return $this->DB->insert_id;
	}

    function delete ($table, $where = false)
    // удаление строки из таблицы $table
    {
        $table = $this->escape($table); 
        if (!$table)
            return $this->noTable();
        $where = $this->parse ($where);
        if ($where)
            $where = "WHERE $where";
        $qry = "DELETE FROM $table $where";
        return $this->query ($qry);
	} 
    
    function createEasy ($table, $fields = false, $defaults = false, $keyPrimary = false, $autoInc = false, $exists = true)
    // создает новую таблицу с именем $table если она не существует либо если $exists = false
    // в переменной $fields заданы поля в виде ассоциативного массива. Их тип во вновь создаваемой таблице 
    // вычисляется по типу значений. Либо, можно задать типы напрямую если значения имеют строковый вид типа
    // _VARCHAR(255) или _TINYINT (1), то есть начинаются с символа '_'
    // $keyPrimary - имя поля первичного ключа
    // $autoInc - массив полей, которые должны иметь автоинкремент
    {
        $result = false;
        if (empty ($fields) or !is_array ($fields))
            return false;
        else
        {
            $str = '';
            foreach ($fields as $key => $value)
            {
                if ($str == '')
                    $comma = '';
                else
                    $comma = ', ';
                $type = gettype ($value);
				switch ($type)
				{
					case 'boolean':
						$t = "TINYINT(1)";
						break;
					case 'integer':
						if (abs ($value) < 2147483648)
							$t = "INT";
						else
							$t = "BIGINT";
						break;
					case 'double':
						$t = "DOUBLE";
						break;
					case 'string':
                        if (strpos ($value, '_') === 0)
                            $t = substr ($value, 1);
                        else
                        {
                            $len = strlen ($value);
                            if ($len < 256)
                                $t = "VARCHAR(255)";
                            elseif ($len < 65536)
                                $t = "TEXT";
                            elseif ($len < 16777216)
                                $t = "MEDIUMTEXT";
                            else
                                $t = "LONGTEXT";
                        }
						break;
					default:
						$t = "VARCHAR(255)";
				}

				if (!empty ($defaults [$key]))
				{
					if (is_string ($value))
						$defaults [$key] = "'" . $defaults [$key] . "'";
					$t .= ' DEFAULT ' . $defaults [$key];
				}
				else
					$t .= ' DEFAULT NULL';
				if (($key == $keyPrimary) || in_array($key, $autoInc))
					$t .= ' AUTO_INCREMENT';
            	$key = str_replace(array("'",'"'), '', $key);
				  $str .= "$comma $key $t"; 
            }
            
            $table = str_replace(array("'",'"'), '', $table);  
            if (empty ($table))
                return false;
            if (empty ($str))
                return false;

            $keyPrimary = str_replace(array("'",'"'), '', $keyPrimary);
			if ($keyPrimary)
				$primary = ", PRIMARY KEY ($keyPrimary)";
			else
				$primary = '';
			if ($exists)
				$exists = " IF NOT EXISTS";
			else
				$exists = '';
            $sql = "CREATE TABLE $exists $table ($str $primary) ENGINE=InnoDB, CHARACTER SET = 'utf8', CONNECTION = 'utf8_general_ci'";
            $result = $this->DB->query ($sql);
        }
    	return $result;
	} 
}
