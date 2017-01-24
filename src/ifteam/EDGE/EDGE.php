<?php

namespace ifteam\EDGE;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\entity\Entity;
use pocketmine\Player;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\entity\Item as ItemEntity;
use pocketmine\network\protocol\RemoveEntityPacket;

class EDGE extends PluginBase implements Listener {
	private static $instance = null; // 인스턴스 변수
	public $messages, $db; // 메시지
	public $economyAPI = null; // 이코노미 API
	public $m_version = 2; // 메시지 버전 변수
	public $packet = [ ]; // 전역 패킷 변수
	public $packetQueue = [ ]; // 패킷 큐
	public $specialLineQueue = [ ];
	public function onEnable() {
		@mkdir ( $this->getDataFolder () );
		
		$this->initMessage ();
		$this->db = (new Config ( $this->getDataFolder () . "pluginDB.yml", Config::YAML, [ 
				"Format" => "%info%\n%online%\n%mymoney%" 
		] ))->getAll ();
		
		if ($this->getServer ()->getPluginManager ()->getPlugin ( "EconomyAPI" ) != null) {
			$this->economyAPI = \onebone\economyapi\EconomyAPI::getInstance ();
		} else {
			$this->getLogger ()->error ( $this->get ( "there-are-no-economyapi" ) );
			$this->getServer ()->getPluginManager ()->disablePlugin ( $this );
		}
		
		$this->getServer ()->getPluginManager ()->registerEvents ( $this, $this );
		if (self::$instance == null)
			self::$instance = $this;
		
		$this->specialLineQueue ["all"] = [ ];
		
		$this->packet ["AddEntityPacket"] = new AddEntityPacket ();
		$this->packet ["AddEntityPacket"]->eid = 0;
		$this->packet ["AddEntityPacket"]->type = ItemEntity::NETWORK_ID;
		$this->packet ["AddEntityPacket"]->x = 0;
		$this->packet ["AddEntityPacket"]->y = 0;
		$this->packet ["AddEntityPacket"]->z = 0;
		$this->packet ["AddEntityPacket"]->speedX = 0;
		$this->packet ["AddEntityPacket"]->speedY = 0;
		$this->packet ["AddEntityPacket"]->speedZ = 0;
		$this->packet ["AddEntityPacket"]->yaw = 0;
		$this->packet ["AddEntityPacket"]->pitch = 0;
		$this->packet ["AddEntityPacket"]->item = 0;
		$this->packet ["AddEntityPacket"]->meta = 0;
		$flags = 0;
		$flags |= 1 << Entity::DATA_FLAG_INVISIBLE;
		$flags |= 1 << Entity::DATA_FLAG_CAN_SHOW_NAMETAG;
		$flags |= 1 << Entity::DATA_FLAG_ALWAYS_SHOW_NAMETAG;
		$flags |= 1 << Entity::DATA_FLAG_IMMOBILE;
		$this->packet ["AddEntityPacket"]->metadata = [ 
				Entity::DATA_FLAGS => [Entity::DATA_TYPE_LONG, $flags],
				Entity::DATA_NAMETAG => [Entity::DATA_TYPE_STRING, ""]
		];
		
		$this->packet ["RemoveEntityPacket"] = new RemoveEntityPacket ();
		
		$this->getServer ()->getPluginManager ()->registerEvents ( $this, $this );
		$this->getServer ()->getScheduler ()->scheduleRepeatingTask ( new EDGETask ( $this ), 20 );
	}
	public function get($var) {
		if (isset ( $this->messages [$this->getServer ()->getLanguage ()->getLang ()] )) {
			$lang = $this->getServer ()->getLanguage ()->getLang ();
		} else {
			$lang = "eng";
		}
		return $this->messages [$lang . "-" . $var];
	}
	public function initMessage() {
		$this->saveResource ( "messages.yml", false );
		$this->messagesUpdate ( "messages.yml" );
		$this->messages = (new Config ( $this->getDataFolder () . "messages.yml", Config::YAML ))->getAll ();
	}
	public function messagesUpdate($targetYmlName) {
		$targetYml = (new Config ( $this->getDataFolder () . $targetYmlName, Config::YAML ))->getAll ();
		if (! isset ( $targetYml ["m_version"] )) {
			$this->saveResource ( $targetYmlName, true );
		} else if ($targetYml ["m_version"] < $this->m_version) {
			$this->saveResource ( $targetYmlName, true );
		}
	}
	// ----------------------------------------------------------------------------------
	public static function getInstance() {
		return static::$instance;
	}
	public function getFormat() {
		// 포멧을 가져옵니다
		return $this->db ["Format"];
	}
	public function setFormat($input) {
		// 포멧을 설정합니다
		return $this->db ["Format"] = $input;
	}
	public function getSpecialLines(Player $player = null) {
		// 추가된 라인들을 가져옵니다
		if ($player == null) {
			// 모든유저들에게 추가된 스페셜라인
			if (isset ( $this->specialLineQueue ["all"] )) {
				return $this->specialLineQueue ["all"];
			} else {
				return false;
			}
		} else {
			// 해당유저가 착용중인 스페셜라인
			if (isset ( $this->specialLineQueue ["player"] [$player->getName ()] )) {
				return [ 
						$this->specialLineQueue ["all"],
						$this->specialLineQueue ["player"] [$player->getName ()] 
				];
			} else {
				return false;
			}
		}
	}
	public function addSpecialLine(Player $player = null, $text) {
		// 라인을 추가합니다
		if ($player == null) {
			// 모든유저들에게 스페셜라인 추가
			$this->specialLineQueue ["all"] [] = $text;
		} else {
			// 해당유저에게만 스페셜라인 추가
			$this->specialLineQueue ["player"] [$player->getName ()] [] = $text;
		}
	}
	public function deleteSpecialLine(Player $player = null, $text) {
		// 스페셜 라인을 가져옵니다
		if ($player == null) {
			// 모든유저들에게 스페셜라인 삭제
			foreach ( $this->specialLineQueue ["all"] as $index => $queue )
				if ($queue == $text) {
					unset ( $this->specialLineQueue ["all"] [$index] );
					break;
				}
		} else {
			// 해당유저에게만 스페셜라인 삭제
			foreach ( $this->specialLineQueue ["player"] [$player->getName ()] as $index => $queue )
				if ($queue == $text) {
					unset ( $this->specialLineQueue ["player"] [$player->getName ()] [$index] );
					break;
				}
		}
	}
	public function onJoin(PlayerJoinEvent $event) {
		$this->specialLineQueue ["player"] [$event->getPlayer ()->getName ()] = [ ];
	}
	public function onQuit(PlayerQuitEvent $event) {
		if (isset ( $this->specialLineQueue ["player"] [$event->getPlayer ()->getName ()] ))
			unset ( $this->specialLineQueue ["player"] [$event->getPlayer ()->getName ()] );
		if (isset ( $this->packetQueue [$event->getPlayer ()->getName ()] ))
			unset ( $this->packetQueue [$event->getPlayer ()->getName ()] );
	}
	// ----------------------------------------------------------------------------------
	public function EDGE() {
		foreach ( $this->getServer ()->getOnlinePlayers () as $OnlinePlayer ) {
			if (isset ( $this->packetQueue [$OnlinePlayer->getName ()] ["x"] )) {
				if ($this->packetQueue [$OnlinePlayer->getName ()] ["x"] != $OnlinePlayer->x)
					if ($this->packetQueue [$OnlinePlayer->getName ()] ["y"] != $OnlinePlayer->y)
						if ($this->packetQueue [$OnlinePlayer->getName ()] ["z"] != $OnlinePlayer->z) {
							$this->packetQueue [$OnlinePlayer->getName ()] ["x"] = $OnlinePlayer->x;
							$this->packetQueue [$OnlinePlayer->getName ()] ["y"] = $OnlinePlayer->y;
							$this->packetQueue [$OnlinePlayer->getName ()] ["z"] = $OnlinePlayer->z;
							continue;
						}
			}
			$this->packetQueue [$OnlinePlayer->getName ()] ["x"] = $OnlinePlayer->x;
			$this->packetQueue [$OnlinePlayer->getName ()] ["y"] = $OnlinePlayer->y;
			$this->packetQueue [$OnlinePlayer->getName ()] ["z"] = $OnlinePlayer->z;

			if (isset ( $this->packetQueue [$OnlinePlayer->getName ()] ["eid"] )) {
				$this->packet ["RemoveEntityPacket"]->eid = $this->packetQueue [$OnlinePlayer->getName ()] ["eid"];
				$OnlinePlayer->dataPacket ( $this->packet ["RemoveEntityPacket"] ); // 네임택 제거패킷 전송
				unset($this->packetQueue [$OnlinePlayer->getName ()] ["eid"]);
			}
			
			if (! $OnlinePlayer->hasPermission ( "edge.showingnametag" ))
				continue;
			$px = round ( $OnlinePlayer->x );
			$py = round ( $OnlinePlayer->y );
			$pz = round ( $OnlinePlayer->z );
			
			$this->packetQueue [$OnlinePlayer->getName ()] ["x"] = $px;
			$this->packetQueue [$OnlinePlayer->getName ()] ["y"] = $py;
			$this->packetQueue [$OnlinePlayer->getName ()] ["z"] = $pz;
			$this->packetQueue [$OnlinePlayer->getName ()] ["eid"] = Entity::$entityCount ++;
			
			$format = str_replace ( "%info%", $this->get ( "serverinfo" ), $this->db ["Format"] );
			$format = str_replace ( "%online%", $this->get ( "usercount" ) . count ( $this->getServer ()->getOnlinePlayers () ), $format );
			$format = str_replace ( "%mymoney%", $this->get ( "mymoney" ) . $this->economyAPI->myMoney ( $OnlinePlayer ), $format );
			
			foreach ( $this->specialLineQueue ["all"] as $queue )
				$format .= "\n" . $queue;
			
			if (isset ( $this->specialLineQueue ["player"] [$OnlinePlayer->getName ()] )) {
				foreach ( $this->specialLineQueue ["player"] [$OnlinePlayer->getName ()] as $queue )
					$format .= "\n" . $queue;
			}
			
			$this->packet ["AddEntityPacket"]->eid = $this->packetQueue [$OnlinePlayer->getName ()] ["eid"];
			$this->packet ["AddEntityPacket"]->metadata [Entity::DATA_NAMETAG] = [ 
					Entity::DATA_TYPE_STRING,
					$format 
			];
			
			$this->packet ["AddEntityPacket"]->x = $px + (- \sin ( ($OnlinePlayer->yaw + 33) / 180 * M_PI ) * \cos ( ($OnlinePlayer->pitch + 5) / 180 * M_PI )) * 7.2;
			$this->packet ["AddEntityPacket"]->y = $py + 3.2 + (- \sin ( $OnlinePlayer->pitch / 180 * M_PI )) * 7.2; // - 3.2
			$this->packet ["AddEntityPacket"]->z = $pz + (\cos ( ($OnlinePlayer->yaw + 33) / 180 * M_PI ) * \cos ( ($OnlinePlayer->pitch + 5) / 180 * M_PI )) * 7.2; // + 0.4
			$OnlinePlayer->dataPacket ( $this->packet ["AddEntityPacket"] );
		}
	}
}
?>
