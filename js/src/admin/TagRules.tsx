import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Select from 'flarum/common/components/Select';

const EXT = 'ernestdefoe-latest-replies';
const t = (key: string) => app.translator.trans(`${EXT}.admin.settings.${key}`, {}, true);

/** Any / Only these / Never per tag, kept as two comma lists of tag ids. */
export default class TagRules extends Component<{ page: any }> {
  view() {
    const page = this.attrs.page;
    const read = (key: string) =>
      String(page.setting(`${EXT}.${key}`)() || '')
        .split(',')
        .filter(Boolean);
    const only = read('include_tags');
    const never = read('exclude_tags');
    const tags = (app.store.all('tags') as any[]).filter((tag) => !tag.isChild?.()).sort((a, b) => (a.position() ?? 1e9) - (b.position() ?? 1e9));

    // 🚨 String keys: integer-like keys would be re-sorted ahead of the others.
    const options = { any: t('tag_any'), only: t('tag_only'), never: t('tag_never') };

    const set = (id: string, value: string) => {
      const next = (list: string[], on: boolean) => [...list.filter((x) => x !== id), ...(on ? [id] : [])].join(',');
      page.setting(`${EXT}.include_tags`)(next(only, value === 'only'));
      page.setting(`${EXT}.exclude_tags`)(next(never, value === 'never'));
    };

    return (
      <div className="Form-group LatestRepliesAdmin-tags">
        <label>{t('tags')}</label>
        <div className="helpText">{t('tags_help')}</div>
        <table className="LatestRepliesAdmin-table">
          <tbody>
            {tags.map((tag) => {
              const id = String(tag.id());
              const value = only.includes(id) ? 'only' : never.includes(id) ? 'never' : 'any';
              return (
                <tr key={id}>
                  <th scope="row">{tag.name()}</th>
                  <td>
                    <Select value={value} options={options} onchange={(v: string) => set(id, v)} aria-label={tag.name()} />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    );
  }
}
