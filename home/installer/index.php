<!DOCTYPE html>
<html>
  <head>
    <meta charset="UTF-8" />
    <title>Phalcon Installer</title>
    <script src="https://unpkg.com/react@16/umd/react.development.js"></script>
    <script src="https://unpkg.com/react-dom@16/umd/react-dom.development.js"></script>
    
    <!-- Don't use this in production: -->
    <script src="https://unpkg.com/babel-standalone@6.15.0/babel.min.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.1.0/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.0/umd/popper.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.1.0/js/bootstrap.min.js"></script>
  </head>
  <body>
    <div id="root"></div>
    <script type="text/babel">
    var data = [
{
  "name":"phalcon-installer",
  "title":"Module Installer",
  "selected":true,
  "required":true
},
{
  "name":"phalcon-module-core",
  "title":"Core",
  "selected":true,
  "required":true
},
{
  "name":"phalcon-module-connector",
  "title":"connector",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-engine",
  "title":"engine",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-angular",
  "title":"angular",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-bigcommerce",
  "title":"bigcommerce",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-bing",
  "title":"bing",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-facebookads",
  "title":"facebookads",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-google",
  "title":"google",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-payment",
  "title":"payment",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-plan",
  "title":"plan",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-rmq",
  "title":"rmq",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-shopify",
  "title":"shopify",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-walmart",
  "title":"walmart",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-shipment",
  "title":"shipment",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-magento",
  "title":"magento",
  "selected":false,
  "required":false
},
{
  "name":"phalcon-module-hubspot",
  "title":"hubspot",
  "selected":false,
  "required":false
},
{
  "name":"shopify-sdk",
  "title":"Shopify Sdk",
  "selected":false,
  "required":false
}

];
      class Home extends React.Component {
  formdata = {};
  constructor(props){
    super();
    //formdata.name
   this.formdata.modules = props.data;
   this.state = {state:0};
    
  }
  handleChange() {
    console.log(this.formdata);
  }
  
  submitForm(){
    var self = this;
    fetch('submit.php',
            {
                method: 'POST',
                headers: {
                    'Accept': 'application/json'
                },
                body: JSON.stringify(this.formdata)
            })
            .then(res => res.json())
            .then((data) => {
                if(data.success){
                  self.setState({state:1});
                  console.log(self.state);
                }
            });
  }
  render() {
    const modules = this.formdata.modules;
    return (
      <div class="container">
      	<div class="row mt-3">
      		<div class="col-12">
            { this.state.state == 0 &&
            <div class="card w-sm-75 w-md-75 w-100 m-auto">
              <div class="card-body">
                <fieldset>
                  <legend>Create below files/directories with write permission:</legend>
                  <div class="row">
                    <ul>
                      <li>app/etc/config.php</li>
                      <li>app/etc/redis.php</li>
                      <li>app/etc/composer.json</li>
                      <li>var</li>
                    </ul>
                  </div>
                </fieldset>
              </div>
            </div>
            }
            <div class="card w-sm-75 w-md-75 w-100 m-auto">
              <div class="card-body">
              { this.state.state == 0 &&
                <form action="/index.php">
                  <fieldset>
                    <legend>Installation Details:</legend>
                    <div class="row">
                      <div class="form-group col-sm-6 col-md-4 col-12" >
                        <label for="app-name">App Name:</label>
                        <input type="text" class="form-control" onChange={(e) => { this.formdata.appname = e.target.value; this.handleChange();}} id="app-name" placeholder="Enter App Name" name="name"/>
                      </div>
                      <div class="form-group col-sm-6 col-md-4 col-12">
                        <label for="dbhost">Db Host:</label>
                        <input type="text" class="form-control" onChange={(e) => { this.formdata.dbhost = e.target.value; this.handleChange();}} id="dbhost" placeholder="Enter Db Host" name="db[host]"/>
                      </div>
                      <div class="form-group col-sm-6 col-md-4 col-12">
                        <label for="dbuser">Db User:</label>
                        <input type="text" class="form-control" onChange={(e) => { this.formdata.dbuser = e.target.value; this.handleChange();}} id="dbuser" placeholder="Enter Db User" name="db[user]" />
                      </div>
                      <div class="form-group col-sm-6 col-md-4 col-12">
                        <label for="dbname">Db Name:</label>
                        <input type="text" class="form-control" onChange={(e) => { this.formdata.dbname = e.target.value; this.handleChange();}} id="dbname" placeholder="Enter Db Name" name="db[dbname]"/>
                      </div>
                      <div class="form-group col-sm-6 col-md-4 col-12">
                        <label for="dbuser">Db Password:</label>
                        <input type="text" class="form-control" onChange={(e) => { this.formdata.dbpassword = e.target.value; this.handleChange();}} id="dbpassword" placeholder="Enter Db Password" name="db[password]"/>
                      </div>

                      <div class="form-group col-sm-6 col-md-4 col-12">
                        <label for="dbuser">Git Host:</label>
                        <input type="text" class="form-control" onChange={(e) => { this.formdata.githost = e.target.value; this.handleChange();}} id="dbpassword" placeholder="Enter Db Password" name="githost"/>
                      </div>
                    </div>
                  </fieldset>
                  <fieldset >
                    <legend>Install Modules:</legend>
                    <div class="row">
                    {
                      modules.map(mod => (

                      
                       <div class="checkbox col-sm-4 col-md-3 col-12">
                          <label>
                          {mod.required ? <input type="checkbox" disabled checked={mod.selected} />:<input type="checkbox" onChange={() => {
                            modules[modules.indexOf(mod)].selected = !modules[modules.indexOf(mod)].selected;
                          }} name="modules[{mod.name}]"/> 
                          }
                          {mod.title}

                          </label>
                        </div>
                    ))
                }
                   </div>
                  </fieldset>
                  <button type="button" onClick={()=>{this.submitForm()}} class="btn btn-default">Submit</button>
        
                 </form>
              }
              { this.state.state == 1 &&
                <div>Installation completed successfully
                    <ul>
                        <li>Run composer install</li>
                        <li>Run php app/cli setup upgrade install</li>
                    </ul>
                    </div>
              }
              </div>
            </div>
      		</div>
      	</div>
        
      </div>
      
    );
  }
}

   
      ReactDOM.render(
        <Home data={data}/>,
        document.getElementById('root')
      );

    </script>
    <!--
      Note: this page is a great way to try React but it's not suitable for production.
      It slowly compiles JSX with Babel in the browser and uses a large development build of React.

      Read this section for a production-ready setup with JSX:
      http://reactjs.org/docs/add-react-to-a-website.html#add-jsx-to-a-project

      In a larger project, you can use an integrated toolchain that includes JSX instead:
      http://reactjs.org/docs/create-a-new-react-app.html

      You can also use React without JSX, in which case you can remove Babel:
      https://reactjs.org/docs/react-without-jsx.html
    -->
  </body>
</html>