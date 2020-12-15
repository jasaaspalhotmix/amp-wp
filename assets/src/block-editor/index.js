/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { registerPlugin } from '@wordpress/plugins';
import { registerBlockType } from '@wordpress/blocks';
import { select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { withFeaturedImageNotice } from '../common/components';
import { getMinimumFeaturedImageDimensions } from '../common/helpers';
import { withMediaLibraryNotice, withDeprecationNotice } from './components';
import { addAMPAttributes, filterBlocksEdit, filterBlocksSave } from './helpers';
import './store';

const {
	isStandardMode,
	getAmpBlocks,
	getAmpBlocksInUse,
} = select( 'amp/block-editor' );

const plugins = require.context( './plugins', true, /.*\.js$/ );

plugins.keys().forEach( ( modulePath ) => {
	const { name, render, icon } = plugins( modulePath );

	registerPlugin( name, { icon, render } );
} );

addFilter( 'blocks.registerBlockType', 'ampEditorBlocks/addAttributes', addAMPAttributes );
addFilter( 'blocks.getSaveElement', 'ampEditorBlocks/filterSave', filterBlocksSave );
addFilter( 'editor.BlockEdit', 'ampEditorBlocks/filterEdit', filterBlocksEdit, 20 );
addFilter( 'editor.PostFeaturedImage', 'ampEditorBlocks/withFeaturedImageNotice', withFeaturedImageNotice );
addFilter( 'editor.MediaUpload', 'ampEditorBlocks/withMediaLibraryNotice', ( InitialMediaUpload ) => withMediaLibraryNotice( InitialMediaUpload, getMinimumFeaturedImageDimensions() ) );

const ampBlocks = getAmpBlocks();
const ampBlocksInUse = getAmpBlocksInUse();

const blocks = require.context( './blocks', true, /(?<!test\/)index\.js$/ );

blocks.keys().forEach( ( modulePath ) => {
	const { name, settings } = blocks( modulePath );

	// Hide AMP dependent blocks that are not currently in use.
	if ( ! ampBlocksInUse.includes( name ) ) {
		return;
	}

	const shouldRegister = isStandardMode() && ampBlocks.includes( name );

	if ( shouldRegister ) {
		settings.edit = withDeprecationNotice( settings.edit );
		registerBlockType( name, settings );
	}
} );
