<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class Kmcmsaccordion extends Module
{
    /** Włącz/wyłącz diagnostykę (komentarz w HTML + log do pliku) */
    private const DIAG = false;

    public function __construct()
    {
        $this->name = 'kmcmsaccordion';
        $this->version = '3.0.0';
        $this->author  = 'KM';
        $this->tab     = 'front_office_features';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('KM CMS Accordion (shortcode)');
        $this->description = $this->l('Wstaw [{accordion id=ID title="Tytuł" open=0/1 group="#selector"}] albo [{alias}] (zdefiniowany w konfiguracji), aby wyświetlić stronę CMS jako harmonijkę.');
        $this->confirmUninstall = $this->l('Usunąć moduł KM CMS Accordion?');
    }

    public function install()
    {
        if (!parent::install()
            || !$this->registerHook('header')
            || !$this->registerHook('actionOutputHTMLBefore')
            || !$this->registerHook('filterOutput')) {
            return false;
        }

        // Tabela aliasów (nie usuwamy jej przy uninstall)
        $sql = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'kmcmsaccordion` (
            `id_kmcmsaccordion` INT(11) NOT NULL AUTO_INCREMENT,
            `alias` VARCHAR(64) NOT NULL,
            `id_cms` INT(11) NOT NULL,
            `title` VARCHAR(255) DEFAULT NULL,
            `open` TINYINT(1) NOT NULL DEFAULT 0,
            `group` VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (`id_kmcmsaccordion`),
            UNIQUE KEY `alias` (`alias`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8';
        return (bool)Db::getInstance()->execute($sql);
    }

    public function uninstall()
    {
        // Celowo NIE usuwamy tabeli, aby zachować aliasy
        return parent::uninstall();
    }

    public function hookHeader()
    {
        if (!$this->context->controller || $this->context->controller->controller_type !== 'front') {
            return;
        }

        $this->context->controller->registerStylesheet(
            'module-'.$this->name,
            'modules/'.$this->name.'/views/assets/css/cmsaccordion.css',
            ['media' => 'all', 'priority' => 150]
        );
        $this->context->controller->registerJavascript(
            'module-'.$this->name,
            'modules/'.$this->name.'/views/assets/js/cmsaccordion.js',
            ['position' => 'bottom', 'priority' => 150]
        );

        $this->diag('INFO', 'header: assets enqueued');
    }

    /**
     * Legacy hook (PS < 8): modyfikuje $params['html'] by-ref.
     */
    public function hookActionOutputHTMLBefore(&$params)
    {
        if (empty($params['html']) || !is_string($params['html'])) {
            return;
        }
        $html =& $params['html'];

        $this->diag('INFO', 'actionOutputHTMLBefore: enter', ['len' => strlen($html)]);

        $context = Context::getContext();
        $idLang  = (int)$context->language->id;
        $idShop  = (int)$context->shop->id;

        $countAccordion = 0;
        $countAlias     = 0;
        $out = $this->replaceShortcodesInHtml($html, $idLang, $idShop, $countAccordion, $countAlias);

        if (self::DIAG) {
            $out = $this->injectDiagMarker($out, 'actionOutputHTMLBefore', $countAccordion, $countAlias);
        }
        $this->diag('INFO', 'actionOutputHTMLBefore: replaced', [
            'accordion' => $countAccordion, 'alias' => $countAlias,
            'changed'   => ($out !== $html ? 'YES' : 'NO')
        ]);

        $html = $out; // zapis z powrotem
        return $html;
    }

    /**
     * PS 8/9: filtr końcowego HTML (zwraca zmodyfikowany HTML).
     */
    public function hookFilterOutput($params)
    {
        $html = null;
        $response = null;

        if (isset($params['html']) && is_string($params['html'])) {
            $html = $params['html'];
        } elseif (isset($params['content']) && is_string($params['content'])) {
            $html = $params['content'];
        } elseif (isset($params['response'])
            && is_object($params['response'])
            && method_exists($params['response'], 'getContent')) {
            $response = $params['response'];
            $html = (string)$response->getContent();
        }

        if (!is_string($html) || $html === '') {
            return isset($params['html']) ? ($params['html'] ?? '') :
                   (isset($params['content']) ? ($params['content'] ?? '') : '');
        }

        $this->diag('INFO', 'filterOutput: enter', ['len' => strlen($html)]);

        $context = Context::getContext();
        $idLang  = (int)$context->language->id;
        $idShop  = (int)$context->shop->id;

        $countAccordion = 0;
        $countAlias     = 0;
        $newHtml = $this->replaceShortcodesInHtml($html, $idLang, $idShop, $countAccordion, $countAlias);

        if (self::DIAG) {
            $newHtml = $this->injectDiagMarker($newHtml, 'filterOutput', $countAccordion, $countAlias);
        }
        $this->diag('INFO', 'filterOutput: replaced', [
            'accordion' => $countAccordion, 'alias' => $countAlias,
            'changed'   => ($newHtml !== $html ? 'YES' : 'NO')
        ]);

        if ($response) {
            $response->setContent($newHtml);
        }
        return $newHtml;
    }

    /**
     * Zamiana shortcode’ów w HTML.
     *
     * @param string $html
     * @param int    $idLang
     * @param int    $idShop
     * @param int    &$countAccordion
     * @param int    &$countAlias
     * @return string
     */
    private function replaceShortcodesInHtml($html, $idLang, $idShop, &$countAccordion = 0, &$countAlias = 0)
    {
        if (!is_string($html) || $html === '') {
            return $html;
        }

        // 1) [{accordion ...}] lub [{cms_accordion ...}]
        $patternAccordion = '/\[\{\s*(?:cms_)?accordion\b([^\}\]]*)\}\]/iu';
        // 2) [{alias}] – wszystko co NIE zaczyna się od (cms_)accordion
        $patternAlias = '/\[\{\s*(?!(?:cms_)?accordion\b)([A-Za-z0-9_-]+)\s*\}\]/u';

        // --- ACCORDION ([{accordion id=... ...}]) ---
        $html = preg_replace_callback($patternAccordion, function ($m) use ($idLang, $idShop, &$countAccordion) {
            $attrsRaw = isset($m[1]) ? trim($m[1]) : '';
            $attrs = [];
            if ($attrsRaw !== '') {
                preg_match_all(
                    '/(\w+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s"\']+)/u',
                    $attrsRaw,
                    $mm,
                    PREG_SET_ORDER
                );
                foreach ($mm as $a) {
                    $key = strtolower($a[1]);
                    $val = $a[2];
                    if (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === '\'' && substr($val, -1) === '\'')) {
                        $val = substr($val, 1, -1);
                    }
                    $attrs[$key] = $val;
                }
            }

            $idCms = isset($attrs['id']) ? (int)$attrs['id'] : 0;
            if ($idCms <= 0) {
                $this->diag('ERROR', 'accordion shortcode without valid id', ['attrs' => $attrs]);
                return ''; // wytnij token
            }

            // open=1/true/tak...
            $openFlag = false;
            if (isset($attrs['open'])) {
                $openVal = strtolower((string)$attrs['open']);
                $truthy  = ['1', 'true', 'yes', 'y', 'on', 'o', 'open', 'tak', 't'];
                if (in_array($openVal, $truthy, true)) {
                    $openFlag = true;
                }
            }

            $groupSelector = isset($attrs['group']) ? trim($attrs['group']) : '';
            $customTitle   = isset($attrs['title']) ? (string)$attrs['title'] : null;

            $cms = new CMS($idCms, $idLang, $idShop);
            if (!Validate::isLoadedObject($cms) || !(bool)$cms->active) {
                $this->diag('ERROR', 'accordion: CMS not found/inactive', [
                    'id_cms' => $idCms, 'id_lang' => $idLang, 'id_shop' => $idShop
                ]);
                return '';
            }

            $titleText = ($customTitle !== null && $customTitle !== '') ? $customTitle : $cms->meta_title;
            $uniq = Tools::passwdGen(8, 'NUMERIC');

            $this->context->smarty->assign([
                'uniqid'  => $uniq,
                'title'   => $titleText,
                'content' => $cms->content, // HTML (w szablonie używamy nofilter)
                'open'    => $openFlag,
                'group'   => $groupSelector,
            ]);
            $countAccordion++;

            return $this->fetch('module:'.$this->name.'/views/templates/_partials/accordion.tpl');
        }, $html);

        // --- ALIAS ([{dostawa}] itd.) ---
        $html = preg_replace_callback($patternAlias, function ($m) use ($idLang, $idShop, &$countAlias) {
            $alias = isset($m[1]) ? trim($m[1]) : '';
            if ($alias === '' || $alias === 'alias') {
                $this->diag('ERROR', 'alias shortcode empty/placeholder', ['alias' => $alias]);
                return '';
            }

            $row = Db::getInstance()->getRow(
                'SELECT * FROM '._DB_PREFIX_.'kmcmsaccordion WHERE alias=\''.pSQL($alias).'\''
            );
            if (!$row) {
                $this->diag('ERROR', 'alias not found', ['alias' => $alias]);
                return '';
            }

            $idCms = (int)$row['id_cms'];
            if ($idCms <= 0) {
                $this->diag('ERROR', 'alias with invalid id_cms', ['alias' => $alias, 'id_cms' => $idCms]);
                return '';
            }

            $cms = new CMS($idCms, $idLang, $idShop);
            if (!Validate::isLoadedObject($cms) || !(bool)$cms->active) {
                $this->diag('ERROR', 'alias: CMS not found/inactive', [
                    'alias' => $alias, 'id_cms' => $idCms, 'id_lang' => $idLang, 'id_shop' => $idShop
                ]);
                return '';
            }

            $titleText     = ($row['title'] !== null && $row['title'] !== '') ? $row['title'] : $cms->meta_title;
            $openFlag      = (bool)$row['open'];
            $groupSelector = $row['group'] ? $row['group'] : '';
            $uniq          = Tools::passwdGen(8, 'NUMERIC');

            $this->context->smarty->assign([
                'uniqid'  => $uniq,
                'title'   => $titleText,
                'content' => $cms->content,
                'open'    => $openFlag,
                'group'   => $groupSelector,
            ]);
            $countAlias++;

            return $this->fetch('module:'.$this->name.'/views/templates/_partials/accordion.tpl');
        }, $html);

        return $html;
    }

    /** Dokleja komentarz diagnostyczny na początek HTML. */
    private function injectDiagMarker($html, $layerName, $accordionCount, $aliasCount)
    {
        $diag = sprintf(
            '<!-- KMCMS DIAG layer=%s; replaced accordion=%d, alias=%d; ts=%s -->',
            $layerName, (int)$accordionCount, (int)$aliasCount, date('c')
        );
        return $diag."\n".$html;
    }

    /** Lekki logger do pliku modules/kmcmsaccordion/kmcmsaccordion.log */
    private function diag($level, $message, array $ctx = [])
    {
        if (!self::DIAG && $level !== 'ERROR') {
            return;
        }
        $file = _PS_ROOT_DIR_.'/modules/'.$this->name.'/'.$this->name.'.log';
        $line = sprintf('[%s] %s %s %s',
            date('c'), strtoupper($level), $message,
            $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($file, $line.PHP_EOL, FILE_APPEND);
    }

    /**
     * Zaplecze modułu (zarządzanie aliasami).
     */
    public function getContent()
    {
        $output = '';

        $idEdit   = (int)Tools::getValue('id');
        $doDelete = Tools::getIsset('delete'.$this->name);
        $doEdit   = Tools::getIsset('edit'.$this->name);
        $doSave   = Tools::isSubmit('submit'.$this->name);

        // DELETE
        if ($doDelete && $idEdit) {
            Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_.'kmcmsaccordion WHERE id_kmcmsaccordion='.(int)$idEdit);
            $output .= $this->displayConfirmation($this->l('Wybrany alias został usunięty.'));
        }

        // SAVE
        if ($doSave) {
            $id     = (int)Tools::getValue('id');
            $alias  = Tools::getValue('alias');
            $id_cms = (int)Tools::getValue('id_cms');
            $title  = Tools::getValue('title');
            $open   = (int)(bool)Tools::getValue('open');
            $group  = Tools::getValue('group');

            if (empty($alias) || !preg_match('/^[A-Za-z0-9_-]+$/', $alias)) {
                $output .= $this->displayError($this->l('Nieprawidłowy alias. Dozwolone: litery, cyfry, myślnik, podkreślenie.'));
            } elseif ($id_cms <= 0) {
                $output .= $this->displayError($this->l('Nieprawidłowy ID strony CMS.'));
            } else {
                if ($id > 0) {
                    // Update istniejącego
                    $exists = Db::getInstance()->getValue(
                        'SELECT id_kmcmsaccordion FROM '._DB_PREFIX_.'kmcmsaccordion 
                         WHERE alias=\''.pSQL($alias).'\' AND id_kmcmsaccordion!='.(int)$id
                    );
                    if ($exists) {
                        $output .= $this->displayError($this->l('Podany alias jest już używany.'));
                    } else {
                        $ok = Db::getInstance()->update('kmcmsaccordion', [
                            'alias'  => pSQL($alias),
                            'id_cms' => (int)$id_cms,
                            'title'  => ($title !== '' ? pSQL($title) : null),
                            'open'   => $open,
                            'group'  => ($group !== '' ? pSQL($group) : null),
                        ], 'id_kmcmsaccordion='.(int)$id);
                        $output .= $ok
                            ? $this->displayConfirmation($this->l('Alias zaktualizowany.'))
                            : $this->displayError($this->l('Nie udało się zapisać zmian.'));
                    }
                } else {
                    // Insert nowego
                    $exists = Db::getInstance()->getValue(
                        'SELECT id_kmcmsaccordion FROM '._DB_PREFIX_.'kmcmsaccordion 
                         WHERE alias=\''.pSQL($alias).'\'' 
                    );
                    if ($exists) {
                        $output .= $this->displayError($this->l('Podany alias jest już używany.'));
                    } else {
                        $sql = 'INSERT INTO '._DB_PREFIX_.'kmcmsaccordion (alias, id_cms, title, open, `group`) VALUES (
                            \''.pSQL($alias).'\', '.(int)$id_cms.',
                            '.($title !== '' ? '\''.pSQL($title).'\'' : 'NULL').',
                            '.$open.',
                            '.($group !== '' ? '\''.pSQL($group).'\'' : 'NULL').'
                        )';
                        $ok = Db::getInstance()->execute($sql);
                        $output .= $ok
                            ? $this->displayConfirmation($this->l('Nowy alias dodany.'))
                            : $this->displayError($this->l('Nie udało się zapisać aliasu.'));
                    }
                }
            }
        }

        // FORM / LISTA
        $form = ['id' => 0, 'alias' => '', 'id_cms' => '', 'title' => '', 'open' => 0, 'group' => ''];
        if ($doEdit && $idEdit) {
            $row = Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'kmcmsaccordion WHERE id_kmcmsaccordion='.(int)$idEdit);
            if ($row) {
                $form['id']     = (int)$row['id_kmcmsaccordion'];
                $form['alias']  = $row['alias'];
                $form['id_cms'] = (int)$row['id_cms'];
                $form['title']  = (string)$row['title'];
                $form['open']   = (int)$row['open'];
                $form['group']  = (string)$row['group'];
            }
        }

        $entries = Db::getInstance()->executeS('SELECT * FROM '._DB_PREFIX_.'kmcmsaccordion ORDER BY alias ASC');

        $output .= '<h4>'.$this->l('Zdefiniowane aliasy').'</h4>';
        if (!empty($entries)) {
            $output .= '<table class="table"><thead><tr>
                <th>'.$this->l('Alias').'</th>
                <th>'.$this->l('ID CMS').'</th>
                <th>'.$this->l('Tytuł (nadpisanie)').'</th>
                <th>'.$this->l('Otwarty').'</th>
                <th>'.$this->l('Grupa (selektor)').'</th>
                <th>'.$this->l('Akcje').'</th>
            </tr></thead><tbody>';
            foreach ($entries as $e) {
                $editUrl = $this->context->link->getAdminLink('AdminModules', true)
                    .'&configure='.$this->name.'&edit'.$this->name.'&id='.(int)$e['id_kmcmsaccordion'];
                $delUrl = $this->context->link->getAdminLink('AdminModules', true)
                    .'&configure='.$this->name.'&delete'.$this->name.'&id='.(int)$e['id_kmcmsaccordion'];

                $output .= '<tr>
                    <td>'.htmlspecialchars($e['alias']).'</td>
                    <td>'.(int)$e['id_cms'].'</td>
                    <td>'.htmlspecialchars((string)$e['title']).'</td>
                    <td>'.($e['open'] ? $this->l('Tak') : $this->l('Nie')).'</td>
                    <td>'.htmlspecialchars((string)$e['group']).'</td>
                    <td>
                        <a class="btn btn-default btn-sm" href="'.$editUrl.'">'.$this->l('Edytuj').'</a>
                        <a class="btn btn-danger btn-sm" href="'.$delUrl.'" onclick="return confirm(\''.$this->l('Usunąć ten wpis?').'\');">'.$this->l('Usuń').'</a>
                    </td>
                </tr>';
            }
            $output .= '</tbody></table><br>';
        } else {
            $output .= '<p>'.$this->l('Brak zdefiniowanych aliasów.').'</p>';
        }

        $formAction = htmlspecialchars($this->context->link->getAdminLink('AdminModules', true).'&configure='.$this->name);
        $btnLabel = ($form['id'] > 0) ? $this->l('Zaktualizuj') : $this->l('Zapisz');

        $output .= '<h4>'.($form['id'] > 0 ? $this->l('Edytuj alias') : $this->l('Dodaj nowy alias')).'</h4>';
        $output .= '<form action="'.$formAction.'" method="post">';
        $output .= '  <input type="hidden" name="id" value="'.(int)$form['id'].'">';
        $output .= '  <div class="form-group">
                         <label>'.$this->l('Alias (użyjesz jako [{alias}])').'</label>
                         <input type="text" name="alias" class="form-control" required value="'.htmlspecialchars($form['alias']).'">
                       </div>';
        $output .= '  <div class="form-group">
                         <label>'.$this->l('ID strony CMS').'</label>
                         <input type="number" name="id_cms" class="form-control" required value="'.htmlspecialchars($form['id_cms']).'">
                       </div>';
        $output .= '  <div class="form-group">
                         <label>'.$this->l('Własny tytuł (opcjonalnie)').'</label>
                         <input type="text" name="title" class="form-control" value="'.htmlspecialchars($form['title']).'" placeholder="'.$this->l('Puste = tytuł z CMS').'">
                       </div>';
        $output .= '  <div class="form-group">
                         <label><input type="checkbox" name="open" value="1" '.($form['open'] ? 'checked' : '').'> '.$this->l('Otwarty domyślnie').'</label>
                       </div>';
        $output .= '  <div class="form-group">
                         <label>'.$this->l('Selektor grupy (opcjonalnie)').'</label>
                         <input type="text" name="group" class="form-control" value="'.htmlspecialchars($form['group']).'" placeholder="#product-infos">
                       </div>';
        $output .= '  <button type="submit" name="submit'.$this->name.'" class="btn btn-primary">'.$btnLabel.'</button>';
        $output .= '</form>';

        $output .= '<hr><p><strong>'.$this->l('Użycie:').'</strong><br>'
                 . $this->l('1) Pełny tag:').' <code>[{accordion id=24 title="Dostawa" open=1 group="#product-details"}]</code><br>'
                 . $this->l('2) Alias z konfiguracji:').' <code>[{dostawa}]</code></p>';

        return $output;
    }
}
