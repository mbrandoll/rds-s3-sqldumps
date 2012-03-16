<?php
class MySQLBackupTest extends PHPUnit_Framework_TestCase
{

	/**
	 *
	 * @var MySQLBackup
	 */
	private $_clientBackup;
	
	protected function setUp()
	{
		$source = array (
				'host' => 'localhost',
				'port' => '3306',
				'username' => 'root',
				'password' => 'xxxxxxxxx'
		);
		
		$target = array(
				'key' => 'xxxxxxxxxxxx',
				'secret' => 'xxxxxxxxxxxxx',
				'instance_id' => 'xxxxxxxxxx'
		);
		
		$options = array(
				'tmp_path' => '/tmp/',
				'split_files_by_size' => '250'
		);
		
		$this->_clientBackup = new MySQLBackup($source, $target, $options);
	}
	
	
	public function testBackupClassTestCreated()
	{
		$this->assertTrue($this->_clientBackup instanceof MySQLBackup);
	}
	

	/**
	 * @expectedException \Exception
	 */
	public function testInvalidMySQLConnection()
	{
		$source = array (
				'host' => '0.0.0.0.0',
				'port' => '3306',
				'username' => 'root',
				'password' => 'xxxxxxxxx'
		);
		
		$target = array(
				'key' => 'xxxxxxxxxxxx',
				'secret' => 'xxxxxxxxxxxxx',
				'instance_id' => 'xxxxxxxxxx'
		);

		
		$client = new MySQLBackup($source, $target);		
		$client->execute();
		
		
	}
}