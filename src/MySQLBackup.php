<?php
class MySQLBackup {

	const SLEEP_BETWEEN_REQUESTS = 5;
	
	/**
	 * source options
	 * 
	 * @var array
	 */
	private $_source = array();
	
	/**
	 * target options
	 * 
	 * @var array
	 */
	private $_target = array();
	


	/**
	 * backup filename
	 * 
	 * @var string
	 */
	private $_filename = null;
	
	
	/**
	 * mysql dump command path
	 * 
	 * @var string
	 */
	private $_mysqldump = null;
	
	/**
	 * tmp dir to save files
	 *
	 * @var string
	 */
	private $_tmp_path = null;
	
	/**
	 * split the backups to smaller chunks
	 * 
	 * @var string
	 */
	private $_splitFilesBySize = null;

	/**
	 * 
	 * @param array $source
	 * @param array $target
	 * @param array $options
	 */
	public function __construct(array $source, array $target, $options = array())
	{
	
		if (isset($options['tmp_path']) && !empty($options['tmp_path'])) {
			$this->_tmp_path = realpath($options['tmp_path']) . '/' . date('Y-m-d_Hi') . '/';
		} else {
			$this->_tmp_path = '/tmp/' . date('Y-m-d_Hi') . '/';
		}
		
		if (!is_dir($this->_tmp_path)) {
			mkdir($this->_tmp_path);
		}
		
		if (isset($options['split_files_by_size']) && !empty($options['split_files_by_size'])) {
			$this->_splitFilesBySize = $options['split_files_by_size'];
		}
				
		$this->_source = $source;
		$this->_target = $target;		
	
		$this->_filename = $source['host'] .".sql";
		
		if (isset($options['mysqldump_path']) && !empty($options['mysqldump_path'])) {
			$this->_mysqldump = $options['mysqldump_path'];
		} else {
			$this->_mysqldump = '/usr/bin/';
		}
	}
	
	
	/**
	 * execute the entire database process
	 */
	public function execute()
	{
		try {
			$this->databaseDump();
			$this->uploadToS3();
							
		} catch (\Exception $e) {
			echo $e->getMessage() . PHP_EOL;
			$this->cleanup();
		}
		
		$this->cleanup();
	}
	

	
	/**
	 * Dump the database into a temp local file
	 */
	private function databaseDump()
	{
		// make sure we have a mysql connection
		$ready = false;
		$tries = 0;
		
		while (!$ready && $tries<=20) {
			$link = mysql_connect($this->_source['host'] . ':' . $this->_source['port'], $this->_source['username'], $this->_source['password']);
				
			if (!$link) {
				echo 'Unable to connect to RDS replica - ' . mysql_error() . PHP_EOL;
			} else {
				$ready = true;
			}
			
			sleep(self::SLEEP_BETWEEN_REQUESTS);
				
			$tries++;
		}
		
		
		// dump the database
		$mysqldump_command = 
				$this->_mysqldump . 'mysqldump' . 
				' --host=' . escapeshellarg($this->_source['host']) .
				' --port=' . escapeshellarg($this->_source['port']) .
				' --user=' . escapeshellarg($this->_source['username']) .
				' --password=' . escapeshellarg($this->_source['password']) .
				' --all-databases' . 
				' --skip-lock-tables' . 
				' --debug-info' .
				' > ' . escapeshellarg($this->_tmp_path . $this->_filename);
	
		$output = '';
		$return_var = 0;
		echo $mysqldump_command . PHP_EOL;
		
		exec($mysqldump_command, $output, $return_var);
		if ($return_var == 2) {
			throw new \Exception('mysqldump dump failed - ' . $mysqldump_command . ' - ' . print_R($output, true));
		}
	
		$extra = '';
		if (is_numeric($this->_splitFilesBySize)) {
			$extra .= '-s ' . $this->_splitFilesBySize . ' ';
		}
	
		// gzip the results
		$gzip_command = 'zip -r ' . $extra . escapeshellarg($this->_tmp_path . $this->_filename . '.zip') . ' ' . escapeshellarg($this->_tmp_path . $this->_filename);
		exec($gzip_command);
		echo $gzip_command . PHP_EOL;

		// remove the original sql dump
		unlink($this->_tmp_path . $this->_filename);
	}
	
	/**
	 * Upload to amazon s3 bucket.
	 */
	private function uploadToS3()
	{
		$options = array('key' => $this->_target['key'], 'secret' => $this->_target['secret']);
		$s3 = new \AmazonS3($options);
		$s3->disable_ssl_verification(false);
				
		$bucket_name = $this->_target['instance_id'];
	
		// create a new bucket, if not exists already
		try {
			$response = $s3->create_bucket($bucket_name, \AmazonS3::REGION_US_E1, \AmazonS3::ACL_PRIVATE);
		} catch (\Exception $e) {
			
		}

		
		if ($dh = opendir($this->_tmp_path)) {
			
			while (($file = readdir($dh)) !== false) {
				if (is_file($this->_tmp_path . $file)) {
					echo 'uploading ' . $this->_tmp_path . $file . PHP_EOL;
					$opt = array (
						'fileUpload' => $this->_tmp_path . $file,
						'acl' => \AmazonS3::ACL_PRIVATE
					);
						
					try {
						$response = $s3->create_object($bucket_name, date('Y-m-d_Hi') . '/' . $file, $opt);
					} catch (\Exception $e) {
						echo $e->getMessage() . PHP_EOL;
					}

					unlink($this->_tmp_path . $file);
				}		
			}
			
			closedir($dh);
		}
		

		
		if ($response->isOK()) {
			return true;
		} else {
			throw new \Exception('s3 upload failed - ' . print_r($response, true));
		}
	}
	
	
	/**
	 * remove the tmp dir
	 */
	private function cleanup()
	{
		echo 'Cleaning up local backups' . PHP_EOL;
		
		rmdir($this->_tmp_path);		
	}
	
}