<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_COLIS_POSITION', columns: ['commande_id', 'position'])]
#[ORM\Index(name: 'IDX_COLIS_COMMANDE', columns: ['commande_id'])]
#[ORM\Index(name: 'IDX_COLIS_EMBALLAGE', columns: ['emballage_id'])]
class ColisCommande
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'colis'), ORM\JoinColumn(nullable: false)]
    private Order $commande;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true)]
    private ?Emballage $emballage = null;
    #[ORM\Column]
    private int $position;
    #[ORM\Column(length: 160)]
    private string $nomEmballage;
    #[ORM\Column]
    private int $capacite;
    #[ORM\Column]
    private int $nombreUnites;
    #[ORM\Column]
    private int $poidsProduitsGrammes;
    #[ORM\Column]
    private int $poidsEmballageGrammes;
    #[ORM\Column]
    private int $poidsTotalGrammes;
    #[ORM\Column(type: Types::JSON)]
    private array $dimensions;
    #[ORM\Column(type: Types::JSON)]
    private array $contenu;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sendcloudId = null;
    #[ORM\Column(nullable: true)]
    private ?int $sendcloudParcelId = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $numeroSuivi = null;
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $statut = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $etiquette = null;

    public function __construct(Order $commande, int $position, array $snapshot, ?Emballage $emballage)
    {
        $this->commande = $commande; $this->position = $position; $this->emballage = $emballage;
        $this->nomEmballage = $snapshot['emballage']['nom']; $this->capacite = $snapshot['emballage']['capacite'];
        $this->nombreUnites = $snapshot['nombre_unites']; $this->poidsProduitsGrammes = $snapshot['poids_produits_g'];
        $this->poidsEmballageGrammes = $snapshot['emballage']['poids_emballage_g']; $this->poidsTotalGrammes = $snapshot['poids_total_g'];
        $this->dimensions = $snapshot['emballage']['dimensions']; $this->contenu = $snapshot['contenu'];
    }
    public function getId(): ?int { return $this->id; }
    public function getCommande(): Order { return $this->commande; }
    public function getEmballage(): ?Emballage { return $this->emballage; }
    public function getPosition(): int { return $this->position; }
    public function getNomEmballage(): string { return $this->nomEmballage; }
    public function getCapacite(): int { return $this->capacite; }
    public function getNombreUnites(): int { return $this->nombreUnites; }
    public function getPoidsProduitsGrammes(): int { return $this->poidsProduitsGrammes; }
    public function getPoidsEmballageGrammes(): int { return $this->poidsEmballageGrammes; }
    public function getPoidsTotalGrammes(): int { return $this->poidsTotalGrammes; }
    public function getDimensions(): array { return $this->dimensions; }
    public function getContenu(): array { return $this->contenu; }
    public function getSendcloudId(): ?string { return $this->sendcloudId; }
    public function getSendcloudParcelId(): ?int { return $this->sendcloudParcelId; }
    public function getNumeroSuivi(): ?string { return $this->numeroSuivi; }
    public function getStatut(): ?string { return $this->statut; }
    public function getEtiquette(): ?string { return $this->etiquette; }
    public function synchronize(string $shipmentId, array $parcel): void
    {
        if (!is_int($parcel['id'] ?? null) || ($this->sendcloudParcelId !== null && $this->sendcloudParcelId !== $parcel['id'])) {
            throw new \DomainException('Le colis Sendcloud reçu ne correspond pas au colis enregistré.');
        }
        $this->sendcloudId = $shipmentId; $this->sendcloudParcelId = $parcel['id'];
        $this->numeroSuivi = is_string($parcel['tracking_number'] ?? null) ? $parcel['tracking_number'] : null;
        $this->statut = $parcel['status']['code'] ?? null;
        $this->etiquette = null;
        foreach ($parcel['documents'] ?? [] as $document) {
            if (($document['type'] ?? '') === 'label') { $this->etiquette = 'sendcloud:parcel:'.$parcel['id']; }
        }
    }
}
