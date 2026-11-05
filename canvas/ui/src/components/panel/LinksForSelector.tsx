import { useParams } from 'react-router';
import { Flex, Text } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import { flushPendingFormEdits } from '@/components/form/react-hook-form/hooks/useDebouncedFormState';
import Select from '@/components/form/components/Select';
import { selectChosenPatternBundle } from '@/features/pattern/patternBundleSlice';
import {
  useGetPatternBindingQuery,
  useRefreshPatternLayoutMutation,
} from '@/services/patternBinding';
import { waitForLayoutSave } from '@/services/preview';

/**
 * Chooses the bundle a Pattern bound to several is mapped and previewed on.
 *
 * It sits above the component form, where the links it changes are made: the
 * form, the preview and the reference menu follow the choice. It says which
 * bundles have no mapping. The choice is kept in the client and named by each
 * request, so nothing is written to the Pattern's draft by making it.
 */
const LinksForSelector = () => {
  const { patternId } = useParams();
  const { data: state, refetch } = useGetPatternBindingQuery(patternId ?? '', {
    skip: !patternId,
  });
  const [refreshLayout] = useRefreshPatternLayoutMutation();
  const bundles = (state?.targets ?? []).map((target) => target.split(':')[1]);
  const chosen = useAppSelector((root) =>
    selectChosenPatternBundle(root, patternId ?? '', bundles),
  );

  // With one bundle, or none, there is nothing to choose.
  if (!patternId || !state || bundles.length < 2) {
    return null;
  }
  const options = state.groups.flatMap((group) => group.options);
  const items = state.targets.map((target) => {
    const bundle = target.split(':')[1];
    const label = options.find((o) => o.value === target)?.label ?? bundle;
    return {
      value: bundle,
      label: state.mapped.includes(bundle) ? label : `${label} · Not mapped`,
      selected: bundle === (chosen ?? bundles[0]),
      type: 'option',
    };
  });

  const choose = async (bundle: string) => {
    // What was typed in the last moments is for the bundle it was typed on.
    flushPendingFormEdits();
    await waitForLayoutSave();
    try {
      // The editor and its forms are read again, for the bundle, which is held
      // as the one shown when the answer arrives: until then the form and the
      // header both still name the old one.
      await refreshLayout({ patternId, bundle }).unwrap();
    } catch {
      // Nothing changed: the old bundle is still the one shown.
    }
  };

  return (
    <Flex direction="column" gap="1" mt="3" data-testid="links-for">
      <Text as="label" size="1" weight="medium" htmlFor="pattern-links-for">
        Links for
      </Text>
      <Select
        attributes={{
          id: 'pattern-links-for',
          value: chosen ?? bundles[0],
          // What is mapped changes as links are made in the editor.
          onFocus: () => refetch(),
          onChange: (event: React.ChangeEvent<HTMLSelectElement>) =>
            choose(event.target.value),
        }}
        options={items}
      />
    </Flex>
  );
};

export default LinksForSelector;
