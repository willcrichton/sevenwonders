<?

class WonderCard {
	protected $name;
	protected $color;
	protected $moneyCost;
	protected $resourceCost;
	protected $age;
	protected $numPlayers;
	protected $command;
	protected $playFunc;
	protected $freePrereq;

	function __construct($args){
		$this->name = $args['name'];
		$this->color = $args['color'];
		$this->moneyCost = $args['moneyCost'];
		$this->resourceCost = $args['resourceCost'];
		$this->age = $args['age'];
		$this->freePrereq = $args['freePrereq'];
		$this->numPlayers = $args['numPlayers'];
		$this->command = $args['command'];
	}

	function getName(){
		return $this->name;
	}

	function getColor(){
		return $this->color;
	}

	function getMoneyCost(){
		return $this->moneyCost;
	}

	function getResourceCost(){
		return $this->resourceCost;
	}

	function getAge(){
		return $this->age;
	}

	function getNumPlayers(){
		return $this->numPlayers;
	}

	function getPrereq(){
		return $this->freePrereq;
	}

	function play($user, $args){
		if($this->moneyCost != 0)
			$user->addCoins(-1 * $this->moneyCost);
		// calculate resources/buying from neighbors here
		// include temp resources
		parseFunc($this->command, $this, $user, $args);
	}

	public function __call($closure, $args)
    {
        call_user_func_array($this->$closure, $args);
    }
}

class WonderDeck {
	public $cards = array();
	protected $hands = array();

	public function addCard($args){
		if(is_array($args['numPlayers'])){
			$args_copy = $args;
			foreach($args['numPlayers'] as $numPlayers){
				/* IMPORTANT IMPORTANT IMPORTANT
				UNCOMMENT THE LINE SO NUMPLAYERS WORKS WHEN IN ACTUAL TESTING */
				$args_copy['numPlayers'] = 1; //$numPlayers;
				$this->cards[] = new WonderCard($args_copy);
			}
		} else {
			$args['numPlayers'] = 1;
			$this->cards[] = new WonderCard($args);
		}
	}

	public function cardInfo($cards, $players = array()){
		$info = array();
		foreach($cards as $id => $card){
			$cInfo = array(
				'name' => $card->getName(),
				'color' => $card->getColor(),
				'id' => $id
			);
			foreach($players as $pl){
				if($pl->getId() == $id and ($pl->isTrashing or $pl->isBuildWonder)) $cInfo['trashing'] = true;
			}
			$info[] = $cInfo;
		}
		return $info;
	}

	public function deal($age, $players){
		$numplayers = count($players);
		$playableCards = array();

		if($age == 3){
			$guilds = array();
			foreach($this->cards as $card){
				if(strpos(strtolower($card->getName()), "guild") !== false){
					$guilds[] = $card;
				}
			}
			shuffle($guilds);
			for($i = 0; $i < $numplayers + 2; $i++){
				$playableCards[] = $guilds[$i];
			}
		}

		foreach($this->cards as $card)
			if($card->getNumPlayers() <= $numplayers and
			   $card->getAge() == $age and 
			   strpos(strtolower($card->getName()), "guild") === false) 
				$playableCards[] = $card;

		shuffle($playableCards);

		foreach($players as $player){
			$hand = array_splice($playableCards, 0, 7);
			$this->hands[$player->getId()] = $hand;
			$player->hand = $hand;
			$packet = packet(array('cards' => $this->cardInfo($hand, array()), 'age' => $age), "hand");
			$player->sendString($packet);
		}
	}
}

$deck = new WonderDeck();

function getResourceCost($str){
	$resources = array(
		'S' => 'stone',
		'T' => 'wood',
		'O' => 'ore',
		'C' => 'clay',
		'L' => 'linen',
		'G' => 'glass',
		'P' => 'paper'
	);
	$cost = array();
	for($i = 0; $i < strlen($str); $i++){
		if(isset($resources[$str[$i]]))
			$cost[$resources[$str[$i]]] = isset($cost[$resources[$str[$i]]]) ? $cost[$resources[$str[$i]]] + 1 : 1;
	}
	return $cost;
}

function getNumPlayers($fields){
	$numplayers = array();
	$last = 0;
	for($i = 7; $i <= 11; $i++){
		$num = intval($fields[$i]);
		if($num > $last){
			$numplayers[] = $i - 4;
			$last = $num;
		}
	}
	return $numplayers;
}

function parseFunc($command, $card, $player, $cards){
	if(strpos($command, 'X') !== false){
		$player->military += strlen($command);
	} elseif(strpos($command, "{") !== false){
		// player gets victory points--do we need to do anything?
		$player->points += intval($command[1]);
	} elseif(preg_match('/<|>/', $command)){
		// buy from neighbors -- IMPLEMENT!!!!!
	} elseif(preg_match('/&|@|#/', $command)){
		$player->addScience($command);
	} elseif(preg_match('/[0-9]/', $command)){
		$player->addCoins(intval($command));
	} else {
		// CHECK FOR DOUBLE RESOURCE VS TWO OPTION RESOURCE
		foreach(getResourceCost($command) as $resource => $amount){
			$player->addResource($resource, $amount, $card->getColor() != 'yellow');
		}
	}
}

function importCards($age){
	global $deck;
	$colors = array('br' => 'brown', 'gy' => 'grey', 'bl' => 'blue', 'y' => 'yellow', 'r' => 'red', 'gr' => 'green', 'p' => 'purple');
	foreach(explode("\r", file_get_contents("cards/age" . $age . ".csv")) as $line){
		$fields = str_getcsv($line);
		$deck->addCard(array(
			'name' => $fields[3],
			'color' => $colors[$fields[2]],
			'moneyCost' => preg_match('/[0-9]/', $fields[1], $matches) ? 1 : 0,
			'resourceCost' => getResourceCost($fields[1]),
			'freePrereq' => $fields[0],
			'age' => $age,
			'numPlayers' => $fields[2] == 'p' ? array(1) : getNumPlayers($fields),
			'command' => $fields[4]
		));
	}
}

importCards(1);
importCards(2);
importCards(3);

?>