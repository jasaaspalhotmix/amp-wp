<?php
/**
 * Class AMP_DailyMotion_Embed_Handler
 *
 * @package AMP
 */

use AmpProject\Dom\Document;

/**
 * Class AMP_DailyMotion_Embed_Handler
 *
 * Much of this class is borrowed from Jetpack embeds
 */
class AMP_DailyMotion_Embed_Handler extends AMP_Base_Embed_Handler {

	/**
	 * The 16:9 aspect ratio in decimal form.
	 *
	 * @var float
	 */
	const RATIO = 0.5625;

	/**
	 * Default width.
	 *
	 * @var int
	 */
	protected $DEFAULT_WIDTH = 600;

	/**
	 * Default height.
	 *
	 * @var int
	 */
	protected $DEFAULT_HEIGHT = 338;

	/**
	 * Default AMP tag to be used when sanitizing embeds.
	 *
	 * @var string
	 */
	protected $amp_tag = 'amp-dailymotion';

	/**
	 * Base URL used for identifying embeds.
	 *
	 * @var string
	 */
	protected $base_embed_url = 'https://www.dailymotion.com/embed/video/';

	/**
	 * AMP_DailyMotion_Embed_Handler constructor.
	 *
	 * @param array $args Height, width and maximum width for embed.
	 */
	public function __construct( $args = [] ) {
		parent::__construct( $args );

		if ( isset( $this->args['content_max_width'] ) ) {
			$max_width            = $this->args['content_max_width'];
			$this->args['width']  = $max_width;
			$this->args['height'] = round( $max_width * self::RATIO );
		}
	}

	/**
	 * Get all raw embeds from the DOM.
	 *
	 * @param Document $dom Document.
	 * @return DOMNodeList A list of DOMElement nodes.
	 */
	protected function get_raw_embed_nodes( Document $dom ) {
		return $dom->xpath->query( sprintf( '//iframe[ starts-with( @src, "%s" ) ]', $this->base_embed_url ) );
	}

	/**
	 * Make embed AMP compatible.
	 *
	 * @param DOMElement $node DOM element.
	 */
	protected function sanitize_raw_embed( DOMElement $node ) {
		$iframe_src = $node->getAttribute( 'src' );

		$video_id = strtok( substr( $iframe_src, strlen( $this->base_embed_url ) ), '/?#' );
		if ( empty( $video_id ) ) {
			return;
		}

		$attributes = [
			'data-videoid' => $video_id,
			'layout'       => 'responsive',
			'width'        => $this->args['width'],
			'height'       => $this->args['height'],
		];

		if ( $node->hasAttribute( 'width' ) ) {
			$attributes['width'] = $node->getAttribute( 'width' );
		}

		if ( $node->hasAttribute( 'height' ) ) {
			$attributes['height'] = $node->getAttribute( 'height' );
		}

		$amp_node = AMP_DOM_Utils::create_node(
			Document::fromNode( $node ),
			$this->amp_tag,
			$attributes
		);

		$this->unwrap_p_element( $node );

		$node->parentNode->replaceChild( $amp_node, $node );
	}
}
