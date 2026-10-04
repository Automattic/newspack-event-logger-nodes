<?php
/**
 * A Remote_Source subclass, for pinning that hub derivation finds a firehose
 * reader by lineage rather than by its literal token.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Fixtures;

\defined( 'ABSPATH' ) || exit;

class Tapir_Pull_Node extends \Newspack_Nodes\Remote_Source_Node {}
