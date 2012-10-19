<?

class WonderCard {
	protected $name;
	protected $color;
	protected $moneyCost;
	protected $resourceCost;
	protected $age;
	protected $numPlayers;
	protected $playFunc;
	protected $freePrereq;

	function __construct($args){
		$this->name = $args['name'];
		$this->color = $args['color'];
		$this->moneyCost = $args['moneyCost'];
		$this->resourceCost = $args['resourceCost'];
		$this->age = $args['age'];
		$this->numplayers = $args['numPlayers'];
		$this->playFunc = $args['playFunc'];
		$this->freePrereq = $args['freePrereq'];
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
		$this->playFunc($user, $args);
	}

	public function __call($closure, $args)
    {
        call_user_func_array($this->$closure, $args);
    }
}

class WonderDeck {
	protected $cards = array();
	protected $hands = array();

	public function __construct($cards){
		if(isset($cards))
			foreach($cards as $card)
				$this->addCard($card);
	}

	public function addCard(WonderCard $card){
		$this->cards[] = $card;
	}

	public function cardNames($cards){
		return array_map(function($card){
			return $card->getName();
		}, $cards);
	}

	public function deal($age, $players){
		$numplayers = count($players);
		$playableCards = array();
		foreach($this->cards as $card)
			if($card->getNumPlayers() <= $numplayers && $card->getAge() == $age) 
				$playableCards[] = $card;

		shuffle($playableCards);

		foreach($players as $player){
			$hand = array_splice($playableCards, 0, 7);
			$this->hands[$player->getId()] = $hand;
			$player->hand = $hand;
			$packet = packet(array('cards' => $this->cardNames($hand)), "hand");
			$player->sendString($packet);
		}
	}
}

$wonderCards = array();
for($i = 1; $i <= 21; $i++){
	$wonderCards[] = new WonderCard(array(
		'name' => "Test $i",
		'color' => 'brown',
		'moneyCost' => 0,
		'resourceCost' => array(),
		'freePrereq' => '',
		'age' => 1,
		'numPlayers' => 1,
		'playFunc' => function($user, $args){
			echo "$user->name hi!";
		}
	));
}
?>