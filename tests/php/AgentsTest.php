<?php
use MAD_Oficina\Core\Agents\Registry;
use MAD_Oficina\Core\Agents\Characters;

/** Personajes y agentes: one place for each agent's model, effort and instructions. */
class AgentsTest extends MADOF_TestCase {

    public function set_up(){
        parent::set_up();
        delete_option(Registry::OPTION);
    }

    public function test_defaults_old_settings_and_her_choice_in_that_order(){
        $this->assertSame('claude-sonnet-5-5', Registry::model('asistente'), 'recommended default');
        $this->assertSame('claude-opus-5-5', Registry::model('finanzas'));
        $this->assertSame('claude-haiku-5-5', Registry::model('secretaria', 'claude-haiku-5-5'), 'what the module had chosen is kept until she saves here');
        Registry::save(['secretaria' => ['model' => 'claude-opus-5-5'], 'asistente' => ['model' => 'gpt-9', 'effort' => 'high', 'extra' => "Dos líneas como mucho.\n<script>"]]);
        $this->assertSame('claude-opus-5-5', Registry::model('secretaria', 'claude-haiku-5-5'));
        $this->assertSame('claude-sonnet-5-5', Registry::model('asistente'), 'an unknown model is ignored');
        $this->assertSame('high', Registry::effort('asistente'));
        $this->assertSame("Dos líneas como mucho.", Registry::extra('asistente'), 'sanitised');
        $this->assertSame('', Registry::extra('secretaria', 'vieja'), 'once saved here, empty means empty');
    }

    public function test_her_instructions_reach_the_agents_after_the_fixed_ones(){
        Registry::save(['proyectos' => ['extra' => 'Llama a las tareas «encargos».'], 'revision' => ['extra' => 'Sé prudente.']]);
        $agent = new \MAD_Oficina\Chat\Agents\Projects(['is_manager' => true, 'member' => null, 'email' => 'j@x', 'manager_email' => 'j@x', 'user_id' => 0]);
        $system = $agent->system_prompt();
        $this->assertStringContainsString('Usa SIEMPRE tus herramientas', $system);
        $this->assertStringEndsWith('Llama a las tareas «encargos».', $system);
        $this->assertStringContainsString('no anulan las reglas', $system);
        $this->assertStringEndsWith('Sé prudente.', \MAD_Oficina\Followup\Reconciler::system_prompt());
        $this->assertStringNotContainsString('encargos', Registry::base_prompt('proyectos'), 'the fixed part shown alone');
        $this->assertSame('claude-sonnet-5-5', \MAD_Oficina\Chat\Agents\Specialist::model('proyectos'));
    }

    public function test_the_page_shows_every_agent_and_saves_names_and_noras_persona(){
        $admin = $this->factory()->user->create(['role' => 'administrator']);
        get_user_by('id', $admin)->add_cap(MAD_Oficina_Core::CAPABILITY);
        wp_set_current_user($admin);
        ob_start(); MAD_Oficina_Core::instance()->render_agents_page(); $html = ob_get_clean();
        foreach ( array_keys(Registry::defs()) as $key ) $this->assertStringContainsString('id="agente-' . $key . '"', $html);
        $this->assertStringContainsString('portraits/contable.png', $html);
        $this->assertStringContainsString('Eres la secretaria de actas', $html, 'the fixed instructions can be read');

        update_option(Characters::OPTION, ['recepcionista' => ['name' => '', 'role' => '', 'greeting' => 'Hola, pasa', 'greeting_alt' => '']]);
        $_POST = $_REQUEST = ['_wpnonce' => wp_create_nonce('madof_agents_save'), 'agents' => ['asistente' => ['model' => 'claude-opus-5-5']],
            'characters' => ['contable' => ['name' => 'Amparo Gil', 'role' => '']], 'nora' => ['name' => 'Noa', 'personality' => 'Seria']];
        try { MAD_Oficina_Core::instance()->handle_agents_save(); } catch ( WPDieException $e ) {} catch ( \Exception $e ) {}
        $this->assertSame('claude-opus-5-5', Registry::model('asistente'));
        $this->assertSame('Amparo Gil', Characters::get('contable')['name']);
        $this->assertSame('Hola, pasa', Characters::get('recepcionista')['greeting'], 'greetings are not touched');
        $this->assertSame('Noa', get_option(MAD_Oficina_Core::option_key('chat'))['assistant_name']);
        $_POST = $_REQUEST = [];
    }
}
