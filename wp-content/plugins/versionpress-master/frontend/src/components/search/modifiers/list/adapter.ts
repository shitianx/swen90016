/// <reference path='../../Search.d.ts' />
/// <reference path='../Adapter.d.ts' />

import { getMatch, trim, IN_QUOTES_REGEXP } from '../../utils/';
import * as ArrayUtils from '../../../../utils/ArrayUtils';

class ListAdapter implements Adapter {

  constructor (private config: SearchConfigItem) {}

  autoComplete = (token: Token) => {
    const value = trim(token.value, true);
    const hints = this.getHints(token);
    const hint = hints[0];
    const hintValue = trim(this.serialize(hint));

    if (value.length && hintValue && hintValue.indexOf(value) === 0) {
      return hint;
    }

    return null;
  }

  getDefaultHint = () => {
    return this.config
      ? this.config.defaultHint!
      : '';
  }

  getHints = (token: Token) => {
    const list = this.config && this.config.content;

    if (list && list.length) {
      if (token && token.type !== 'space' && (token.modifier.length || token.value.length)) {
        const value = trim(token.value, true);

        const labelMatches = getMatch(value, list, 'label');
        const valueMatches = getMatch(value, list, 'value');

        return labelMatches
          .concat(valueMatches)
          .filter((value, index, self) => self.indexOf(value) === index)
          .filter(item => item.value !== value)
          .sort((a, b) => a.value.length - b.value.length);
      }

      return list
        .filter(item => item.modifier)
        .sort((a, b) => a.value.length - b.value.length);
    }

    return [];
  }

  isValueValid = (value: string) => {
    const list = this.config && this.config.content;

    if (list) {
      return list.some(item => trim(this.serialize(item)) === trim(value));
    }
    return !!value;
  }

  serialize = (item: SearchConfigItemContent) => {
    if (!item) {
      return '';
    }
    if (IN_QUOTES_REGEXP.test(item.value) || item.value.indexOf(' ') === -1) {
      return item.value;
    }
    return '"' + item.value + '"';
  }

  deserialize = (value: string) => {
    const list = this.config && this.config.content;

    if (list) {
      return ArrayUtils.find(list, item => item!.value === value) || '';
    }
    return value;
  }

}

export default ListAdapter;
