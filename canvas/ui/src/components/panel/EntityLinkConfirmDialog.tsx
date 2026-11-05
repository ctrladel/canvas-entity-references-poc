import { AlertDialog, Button, Flex, Text } from '@radix-ui/themes';

interface EntityLinkConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  // The reassurance, in gray under the title.
  description: string;
  // What the change does, one line each.
  effects?: string[];
  // Without it the dialog only reports, and closes.
  confirmLabel?: string;
  onConfirm?: () => void;
  loading?: boolean;
  cancelLabel?: string;
  // `<prefix>-dialog`, `-warning`, `-cancel` and `-confirm`.
  testIdPrefix: string;
}

/**
 * The decision that is too much for a menu: a change that clears links. Used
 * for a Pattern's binding and for a component's reference.
 */
const EntityLinkConfirmDialog = ({
  open,
  onOpenChange,
  title,
  description,
  effects = [],
  confirmLabel,
  onConfirm,
  loading = false,
  cancelLabel = 'Cancel',
  testIdPrefix,
}: EntityLinkConfirmDialogProps) => (
  <AlertDialog.Root open={open} onOpenChange={onOpenChange}>
    <AlertDialog.Content
      size="2"
      maxWidth="420px"
      data-testid={`${testIdPrefix}-dialog`}
    >
      <AlertDialog.Title size="3">{title}</AlertDialog.Title>
      <div data-testid={`${testIdPrefix}-warning`}>
        <AlertDialog.Description size="2" color="gray" mb="2">
          {description}
        </AlertDialog.Description>
        {effects.length > 0 && (
          <Flex direction="column" gap="1">
            {effects.map((line) => (
              <Text as="p" size="2" key={line}>
                {line}
              </Text>
            ))}
          </Flex>
        )}
      </div>
      <Flex gap="3" mt="4" justify="end">
        <AlertDialog.Cancel>
          <Button
            variant="soft"
            color="gray"
            data-testid={`${testIdPrefix}-cancel`}
          >
            {cancelLabel}
          </Button>
        </AlertDialog.Cancel>
        {confirmLabel && onConfirm && (
          <Button
            data-testid={`${testIdPrefix}-confirm`}
            loading={loading}
            onClick={onConfirm}
          >
            {confirmLabel}
          </Button>
        )}
      </Flex>
    </AlertDialog.Content>
  </AlertDialog.Root>
);

export default EntityLinkConfirmDialog;
