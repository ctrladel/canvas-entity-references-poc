import clsx from 'clsx';

import PropLabelControls, {
  parseLabelData,
} from '@/components/form/components/drupal/PropLabelControls';
import PropLinker from '@/components/form/components/drupal/PropLinker';
import FormElementLabel from '@/components/form/components/FormElementLabel';
import { a2p } from '@/local_packages/utils.js';

import type { PropLinkerProps } from '@/components/form/components/drupal/PropLinker';
import type { Attributes } from '@/types/DrupalAttribute';

// Account for linker data being snake_case when it arrives via attributes.
interface PropLinkData extends Omit<PropLinkerProps, 'propName'> {
  prop_name: string;
}

const DrupalFormElementLabel = ({
  title = '',
  titleDisplay = '',
  required = '',
  attributes = {},
  directLinkerData = undefined,
  propLabelData = undefined,
}: {
  title: string;
  titleDisplay?: string;
  required?: string;
  attributes?: Attributes;
  directLinkerData?: PropLinkData;
  // The same as `prop_label_data` of the attributes, for the labels of a
  // fieldset legend, which the theme hands it directly.
  propLabelData?: unknown;
}) => {
  const classes = clsx(
    titleDisplay === 'after' ? 'option' : '',
    titleDisplay === 'invisible' ? 'visually-hidden' : '',
    required ? 'js-form-required' : '',
    required ? 'form-required' : '',
  );
  const show = !!title || !!required;

  // The basic form label
  const theLabel = (
    <FormElementLabel
      attributes={a2p(
        attributes,
        {},
        { skipAttributes: ['class', 'prop_link_data', 'prop_label_data'] },
      )}
      className={classes}
    >
      {title}
    </FormElementLabel>
  );
  const getTheLabel = () => {
    // If there is prop link data, render the PropLinker next to the label.
    // We wrap the label in a div so they can appear next to each other without
    // the linker appearing inside the <label> tag.
    const labelData = parseLabelData(
      propLabelData ?? attributes?.prop_label_data,
    );
    if (
      titleDisplay !== 'invisible' &&
      (attributes?.prop_link_data || directLinkerData || labelData)
    ) {
      const propLinkData: PropLinkData | undefined = directLinkerData
        ? directLinkerData
        : (attributes.prop_link_data as PropLinkData | undefined);
      return (
        <div className="canvas-linked-prop-label-wrapper">
          {theLabel}
          {propLinkData && (
            <PropLinker
              propName={propLinkData.prop_name}
              linked={propLinkData.linked}
              suggestions={propLinkData.suggestions}
            />
          )}
          {labelData && (
            <PropLabelControls data={labelData} defaultLabel={String(title)} />
          )}
        </div>
      );
    }

    // If there are no prop links, just return the label.
    return theLabel;
  };

  return show && getTheLabel();
};

export default DrupalFormElementLabel;
