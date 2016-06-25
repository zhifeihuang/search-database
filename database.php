<?php
/* THE GPLV3 Lincese
 *
 * Search database.
 * Author: ZhiFeiHuang
 */
 
class database {
private $needle = ',';

public function __construct($db) {
	$this->db = $db;
}

public function search_suggestions($search, $tstruct, $conv, $conversion, $sugg=true, $offset=0, $limit=25) {
	$is_string = false;
	if (is_numeric($search)) {
		$val = '%'. str_replace('- ', '', $search) .'%';
		$search_k = $tstruct['number'];
	} else {
		$is_string = true;
		$val = '%'. strtolower($search) .'%';
		$search_k = $tstruct['string'];
	}
	
	$tmp_k = $search_k;
	$ct = false;
	$i = 0;
	foreach ($search_k as $v) {
		if (!isset($search_k[$i])) {
			++$i;
			continue;
		}
		
		$search_v[] = $val;
		if (isset($conv[$v])) {
			$ct = true;
			call_user_func_array($conversion, array(&$search_k, &$search_v, $i));
		}
		++$i;
	}
	
	if ($ct === true) {
		$search_k = array_values($search_k);
	}
	
	$query = $this->query;
	foreach ($search_k as $v) {
		$query .= ($is_string ? 'LOWER('. $v .')' : $v) . ' LIKE ? OR ';
	}
	
	$query = rtrim($query, 'OR ') . ') '. $this->order .' LIMIT ?,?';
		
	$suggestions = array();
	
	try {
		$stmt = $this->db->prepare($query);
		$i = 1;
		foreach ($search_v as &$v) {
			$stmt->bindParam($i++, $v, PDO::PARAM_STR);
		}
		$stmt->bindParam($i++, $offset, PDO::PARAM_INT);
		$stmt->bindParam($i, $limit, PDO::PARAM_INT);
		$stmt->execute();
	} catch (PDOException $e) {
		error_log($e->getMessage());
		return $suggestions;
	}
	
	if ($sugg) {
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$value = $label = null;
			foreach ($tmp_k as $v) {
				$idx = strpos($v, '.');
				$idx = $idx === false ? 0 : $idx + 1;
				$key = substr($v, $idx);
				$value .= $key . $this->needle;
				$label .= $row[$key] . $this->needle;
			}
			$label = rtrim($label, $this->needle);
			foreach ($suggestions as $v) {
				if (strcmp($label, $v['label']) === 0) {
					$label = null;
					break;
				}
			}
			if ($label === null) continue;
			
			$suggestions[] = array('value' => rtrim($value, $this->needle), 'label' => $label);
		}
	} else {
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			foreach ($row as $k => $v) {
				$c[$k] = $v; 
			}
			
			$suggestions[] = $c;
		}
	}
	
	return $suggestions;
}

public function search($var, $conv, $conversion, $offset=0, $limit=100) {
	$is_accuracy = true;
	$suggestions = array();
	$search_k = array();
	$search_v = array();
	if (!empty($var['value'])) {
		$search_k = explode($this->needle, $var['value']);
		$search_v = explode($this->needle, $var['label']);
		$i = count($search_k);
		if (count($search_v) != $i) return $suggestions;
		
		do {
			$i--;
			if (empty($search_v[$i])) {
				unset($search_v[$i], $search_k[$i]);
				continue;
			}
		} while ($i);
		
		$search_k = array_values($search_k);
		$search_v = array_values($search_v);
	} else if (strpos($var['label'], '=')) {
		$is_accuracy = false;
		/* count(=) <= 2
		 * we hope the data like k1 = v1 k2 = v2 ...   or   k1 = v1, k2 = v2 ...
		 * after preg_replace, it will become k1=v1 k2=v2 ...
		 * so we will get data like array('k1', 'v1 k2', 'v2 ...')
		 * also replace ' ' to '' just for name.
		*/
		$str = preg_replace(array('/\s*=\s*/', '/,/'), array('=', ' '), $var['label'], 2);
		$tmp = explode('=', strtolower($str), 3);
		if (count($tmp) > 2) {
			$search_k[] = $tmp[0];
			if (($idx = strrpos($tmp[1], ' ')) === false) {
				$search_v[] = '%' . $tmp[1] . '%';
			} else {
				$idx2 = strpos($tmp[2], ' ');
				$search_k[] = substr($tmp[1], $idx+1);
				$search_v[] = '%' . substr($tmp[1], 0, $idx) . '%';
				$search_v[]  = '%' . ($idx2 > 0 ? substr($tmp[2], 0, $idx2) : $tmp[2]) . '%';
			}
		} else {
			$search_k[] = $tmp[0];
			$search_v[] = '%' . $tmp[1] . '%';
		}
	} else {
		// no suggest or accuracy search, hope people dont do this;
		return -1;
	}
	
	$ct = false;
	$i = 0;
	foreach ($search_k as $v) {
		if (!isset($search_k[$i])) {
			++$i;
			continue;
		}
		
		if (isset($conv[$v])) {
			$ct = true;
			call_user_func_array($conversion, array(&$search_k, &$search_v, $i));
		}
		++$i;
	}
	
	if ($ct === true) {
		$search_k = array_values($search_k);
		$search_v = array_values($search_v);
	}
	
	
	$query = $this->query;
	foreach ($search_k as $v) {
		$query .= $is_accuracy ? $v.'=? AND ' : 'LOWER('. $v.') LIKE ? AND ';
	}
	$query = rtrim($query, 'AND ') . $this->order .' LIMIT ?,?';
	
	try {
		$stmt = $this->db->prepare($query);
		$i = 1;
		foreach ($search_v as &$v) {
			$stmt->bindParam($i++, $v, $this->get_param($v));
		}
		$stmt->bindParam($i++, $offset, PDO::PARAM_INT);
		$stmt->bindParam($i, $limit, PDO::PARAM_INT);
		
		$stmt->execute();
	}  catch (PDOException $e) {
		error_log($e->getMessage());
		return $suggestions;
	}
	
	while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		foreach ($row as $k => $v) {
			$c[$k] = $v; 
		}
		
		$suggestions[] = $c;
	}
	
	return $suggestions;
}

private function get_param(&$data) {
	if (is_int($data)) return PDO::PARAM_INT;
	if (is_float($data) || is_double($data) || is_string($data)) return PDO::PARAM_STR;
	if (is_bool($data)) return PDO::PARAM_BOOL;
	if (is_null($data)) return PDO::PARAM_NULL;
}
}
?>
