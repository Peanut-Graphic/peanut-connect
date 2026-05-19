declare const wp: any;

const { registerBlockType } = wp.blocks;
const { useBlockProps, InspectorControls } = wp.blockEditor;
const { PanelBody, TextControl } = wp.components;
const { createElement: el, Fragment } = wp.element;

registerBlockType('peanut-connect/video', {
  edit: ({ attributes, setAttributes }: any) => {
    const props = useBlockProps();
    return el(Fragment, {},
      el(InspectorControls, {},
        el(PanelBody, { title: 'Video' },
          el(TextControl, {
            label: 'Video slug',
            help: 'From Connect → Videos (the value in [peanut_video slug="…"]).',
            value: attributes.slug || '',
            onChange: (slug: string) => setAttributes({ slug }),
          })
        )
      ),
      el('div', props,
        attributes.slug
          ? el('p', {}, `Peanut Video: ${attributes.slug} (renders on the front end)`)
          : el('p', {}, 'Peanut Video — set a slug in the block sidebar.')
      )
    );
  },
  save: () => null,
});
