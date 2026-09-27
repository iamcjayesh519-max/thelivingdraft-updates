/**
 * The Custom code block.
 *
 * Written in plain JavaScript on purpose — no JSX, no npm, no build step, so
 * the file you can read here is exactly the file that runs. It cannot break
 * when a build tool changes.
 */
(function (blocks, element, blockEditor, components, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType('livingdraft/custom', {
		edit: function (props) {
			var attrs = props.attributes;
			var blockProps = blockEditor.useBlockProps({ className: 'ld-custom-editor' });

			function field(label, key, help, rows) {
				return el(
					components.TextareaControl,
					{
						label: label,
						help: help,
						value: attrs[key] || '',
						rows: rows || 6,
						onChange: function (value) {
							var change = {};
							change[key] = value;
							props.setAttributes(change);
						}
					}
				);
			}

			return el(
				'div',
				blockProps,
				el(
					components.Panel,
					{},
					el(
						components.PanelBody,
						{ title: __('Custom code', 'livingdraft-core'), initialOpen: true },
						el(
							'p',
							{ style: { fontSize: '12px', color: '#666', marginTop: 0 } },
							__('Paste HTML, CSS and JavaScript. Do not include <style> or <script> tags — they are added for you.', 'livingdraft-core')
						),
						field(__('HTML', 'livingdraft-core'), 'html', __('The markup for this block.', 'livingdraft-core'), 8),
						field(__('CSS', 'livingdraft-core'), 'css', __('Styles. Use var(--mark) and var(--ink) to follow your theme colours.', 'livingdraft-core'), 6),
						field(__('JavaScript', 'livingdraft-core'), 'js', __('Runs inside its own wrapper, so it cannot clash with the page.', 'livingdraft-core'), 6)
					)
				)
			);
		},

		// Rendered in PHP, so nothing is saved but the attributes.
		save: function () {
			return null;
		}
	});
}(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
));
